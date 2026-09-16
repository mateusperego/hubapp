<?php

namespace HubApp\Relay;

use Workerman\Connection\TcpConnection;

/**
 * O repasse dos frames de tela e a política de descarte.
 *
 * Regra de ouro: **vídeo nunca entra em fila.** Um frame enfileirado será
 * exibido tarde e empurra todos os seguintes para ainda mais tarde; a fila nunca
 * se recupera sozinha, porque a fonte produz a 30 fps constantes. A única saída
 * é descartar — e descartar até um keyframe, porque frame inter não significa
 * nada sem o anterior.
 *
 * O `send()` do Workerman não ajuda aqui: quando o buffer enche ele chama
 * `onError` e descarta *o frame mais novo*, que pode ser justamente o keyframe
 * que repararia a imagem. Por isso o pré-cheque com `getSendBufferQueueSize()`
 * é obrigatório e não uma otimização — e por isso ele soma o tamanho do frame
 * antes de decidir, e não só o que já está na fila.
 */
class MirrorRouter
{
    /** Uma linha de aviso por sessão por essa janela, com o resto agregado. */
    private const WARN_EVERY_SECONDS = 5.0;

    private MirrorRegistry $mirrors;
    private ConnectionRegistry $registry;

    /** @var array<string, array{at: float, suppressed: int}> */
    private array $warnWindow = [];

    public function __construct(MirrorRegistry $mirrors, ConnectionRegistry $registry)
    {
        $this->mirrors  = $mirrors;
        $this->registry = $registry;
    }

    /**
     * Um frame da fonte, a caminho de todos os painéis da sessão.
     */
    public function forward(TcpConnection $source, string $frame): void
    {
        $key = $this->mirrors->sessionKeyOf($source);

        if ($key === null || !$this->mirrors->isSource($key, $source)) {
            return;
        }

        $header = MirrorFrame::readHeader($frame);

        if ($header === null) {
            // Descartar e seguir, como em todo o resto do relay: o próximo
            // keyframe repara a imagem sozinho, e fechar a conexão custaria a
            // sessão inteira por um frame ruim.
            $this->warnOnce(
                $key,
                'frame de espelhamento inválido na sessão '
                . ($this->mirrors->session($key)['session_id'] ?? '?')
            );
            return;
        }

        $size = strlen($frame);

        // Adia o watchdog de fonte morta e alimenta a medição. Vem antes de
        // qualquer decisão de repasse: um frame que chegou conta como sinal de
        // vida mesmo que não haja ninguém para quem mandá-lo.
        $this->mirrors->recordFrame($key, $header, $size);

        $keyframe   = MirrorFrame::isKeyframe($header);
        $disposable = MirrorFrame::isDisposable($header);

        // Só o frame que é *apenas* SPS/PPS entra no cache. O aparelho também
        // prega os parameter sets na frente de todo IDR, e guardar um IDR aqui
        // encheria a sessão com 120 KB que envelhecem — o cache existe para
        // caber num punhado de bytes e ser replicado a quem entra.
        $configOnly = MirrorFrame::isParameterSets($header) && !$keyframe;

        if ($configOnly) {
            $this->mirrors->rememberConfig($key, $frame);
        }

        $sinks = $this->mirrors->sinksOf($key);

        if ($sinks === []) {
            // Ninguém assistindo. O timer de ociosidade manda o aparelho parar;
            // aqui é só contar — são bytes da franquia do vendedor indo embora,
            // e até agora isso não aparecia em lugar nenhum.
            $this->mirrors->countNoSinkFrame($key);
            return;
        }

        // Três sinais distintos, porque levam a remédios distintos.
        $congested       = 0;     // painéis que não estão acompanhando
        $freshDrop       = false; // algum acabou de cair agora
        $drainedAndStuck = false; // algum drenou e está sem ponto de retomada

        foreach ($sinks as $sink) {
            // SPS/PPS sozinhos passam em qualquer estado e nunca contam como
            // descarte: são centenas de bytes e sem eles o keyframe seguinte é
            // inútil. Um IDR não entra aqui — ele é grande e é justamente o que
            // tira o painel de `warming`.
            if ($configOnly) {
                $sink->send($frame);
                continue;
            }

            $queue = $sink->getSendBufferQueueSize();
            $state = $this->mirrors->stateOf($key, $sink);

            $this->mirrors->observeQueue($key, $queue);

            if ($state !== 'flowing') {
                // Quem está em `dropping` continua contando como atolado
                // enquanto não voltar. Sem isto o congestionamento só era
                // visível no frame exato da transição — e nesse instante a taxa
                // medida ainda podia ser zero, então o pedido de bitrate menor
                // nunca saía. Quem está em `warming` não entra: esse não caiu,
                // esse ainda não começou.
                if ($state === 'dropping') {
                    $congested++;
                }

                if ($keyframe && $queue <= $this->mirrors->lowWater()) {
                    if ($state === 'dropping') {
                        RelayLog::info(
                            'painel voltou a acompanhar no espelhamento '
                            . ($this->mirrors->session($key)['session_id'] ?? '?')
                        );
                    }
                    $this->mirrors->setState($key, $sink, 'flowing');
                    $this->mirrors->clearDrops($key, $sink);
                    $sink->send($frame);
                    $this->mirrors->countForwarded($key);
                    continue;
                }

                $drops = $this->mirrors->countDrop($key, $sink);

                // Duas razões para pedir um ponto de retomada, e a segunda é a
                // que faltava: o painel já drenou a fila e continua sem
                // keyframe nenhum para recomeçar. Antes só o primeiro descarte
                // pedia, então um painel que drenasse depois disso ficava preso
                // em `dropping` para sempre, à espera de que outro pedisse por
                // ele. O limite de 500 ms por sessão é o que impede que pedir
                // em todo frame vire uma rajada de IDR.
                if ($drops === 1) {
                    $freshDrop = true;
                }

                if ($queue <= $this->mirrors->lowWater()) {
                    $drainedAndStuck = true;
                }
                continue;
            }

            if ($queue + $size > $this->mirrors->highWater()) {
                $congested++;

                // Frame que ninguém referencia sai sozinho e não estraga o que
                // vem depois: dá para perder este quadro e seguir entregando os
                // próximos, em vez de congelar a imagem até o IDR seguinte.
                if ($disposable) {
                    $this->mirrors->countDrop($key, $sink);
                    continue;
                }

                $this->mirrors->setState($key, $sink, 'dropping');

                if ($this->mirrors->countDrop($key, $sink) === 1) {
                    $freshDrop = true;
                }

                $this->warnOnce(
                    $key,
                    'painel lento no espelhamento '
                    . ($this->mirrors->session($key)['session_id'] ?? '?')
                    . ": {$queue} bytes na fila; descartando até o keyframe"
                );
                continue;
            }

            $sink->send($frame);
            $this->mirrors->countForwarded($key);
        }

        // Quem está segurando o fluxo muda o remédio, e até agora os dois casos
        // recebiam o mesmo. Se **todos** os painéis estão atolados, o problema
        // não é um painel: é a taxa que a fonte está produzindo para o enlace
        // que existe, e o que cabe é pedir menos bitrate.
        $allCongested = $congested > 0 && $congested === count($sinks);

        if ($allCongested) {
            $this->askTune($key);
        }

        // O ponto de retomada é outra conversa, e não dá para simplesmente não
        // pedir: sem keyframe o painel não volta nunca. O que se evita é pedir
        // no pior momento. Um painel que já drenou a fila pode receber um IDR —
        // ele tem folga agora, e é disso que precisa para sair de `dropping`.
        // Já o primeiro descarte só justifica um IDR quando o problema é de um
        // painel entre vários; se todos estão atolados, somar ~120 KB no uplink
        // do celular que já não dá conta é o remédio piorando a doença.
        if ($drainedAndStuck || ($freshDrop && !$allCongested)) {
            $this->askKeyframe($key, 'congestion');
        }
    }

    /**
     * Pede um IDR ao aparelho pelo canal de **controle**.
     *
     * Não pelo socket de mídia: aquele é binário e de mão única, e inventar um
     * comando reverso nele seria criar um segundo protocolo para dizer uma
     * frase. Este é um dos poucos pontos em que o relay escreve uma mensagem de
     * aplicação em vez de repassar uma, e a exceção é deliberada — ele é a
     * única parte do sistema que enxerga o buffer de saída encher.
     */
    public function askKeyframe(string $key, string $reason): void
    {
        $session = $this->mirrors->session($key);

        if ($session === null || !$this->mirrors->mayAskKeyframe($key)) {
            return;
        }

        $this->registry->seller($session['seller_id'])?->send(Envelope::encode([
            'type'       => 'mirror_keyframe',
            'session_id' => $session['session_id'],
            'reason'     => $reason,
        ]));
    }

    /**
     * Pede ao aparelho que baixe o bitrate.
     *
     * O bitrate sai de uma fração da taxa que o relay **mediu** chegando — ele
     * recebe cada frame, então é a ponta que sabe disso melhor que qualquer
     * outra, e assim não precisa interpretar nenhuma mensagem de aplicação para
     * descobrir o que o aparelho está usando.
     *
     * Ao contrário do keyframe, isto é barato e imediato no aparelho: o encoder
     * aceita bitrate novo sem reconfigurar e sem parameter sets novos.
     */
    public function askTune(string $key): void
    {
        $session = $this->mirrors->session($key);

        if ($session === null) {
            return;
        }

        $bitrate = $this->mirrors->nextTuneBitrate($key);

        if ($bitrate === null) {
            return;
        }

        $this->registry->seller($session['seller_id'])?->send(Envelope::encode([
            'type'       => 'mirror_tune',
            'session_id' => $session['session_id'],
            'bitrate'    => $bitrate,
        ]));

        RelayLog::info(
            "espelhamento {$session['session_id']}: todos os painéis atolados; "
            . 'pedindo ' . (int) round($bitrate / 1000) . ' kbps'
        );
    }

    /** Entrega ao painel recém-chegado o SPS/PPS guardado e pede um keyframe. */
    public function warmUp(string $key, TcpConnection $sink): void
    {
        $config = $this->mirrors->lastConfig($key);

        if ($config !== null) {
            $sink->send($config);
        }

        $this->askKeyframe($key, 'join');
    }

    /** Diz ao aparelho para parar de codificar. */
    public function tellSourceToStop(string $sellerId, string $sessionId, string $reason): void
    {
        $this->registry->seller($sellerId)?->send(Envelope::encode([
            'type'       => 'mirror_stop',
            'session_id' => $sessionId,
            'reason'     => $reason,
        ]));
    }

    /** Avisa os painéis de que a transmissão acabou, pelo canal de controle. */
    public function announceStop(string $sellerId, string $sessionId, string $reason): void
    {
        $frame = Envelope::wrap($sellerId, [
            'type'       => 'mirror_stopped',
            'session_id' => $sessionId,
            'reason'     => $reason,
        ]);

        foreach ($this->registry->panels() as $panel) {
            $panel->send($frame);
        }
    }

    public function forgetWarnWindow(string $key): void
    {
        unset($this->warnWindow[$key]);
    }

    /**
     * Um aviso por sessão por janela, com o que foi suprimido no fim.
     *
     * Estes avisos nascem no caminho de mídia, ou seja, podem disparar trinta
     * vezes por segundo — e cada linha de log é uma escrita em disco no meio do
     * event loop. Sem a janela, o log de congestionamento é ele mesmo uma causa
     * de congestionamento, exatamente no instante em que o loop menos tem folga.
     */
    private function warnOnce(string $key, string $message): void
    {
        $now    = microtime(true);
        $window = $this->warnWindow[$key] ?? ['at' => 0.0, 'suppressed' => 0];

        if ($now - $window['at'] < self::WARN_EVERY_SECONDS) {
            $this->warnWindow[$key]['suppressed'] = $window['suppressed'] + 1;
            return;
        }

        if ($window['suppressed'] > 0) {
            $message .= " (+{$window['suppressed']} iguais nos últimos "
                . (int) self::WARN_EVERY_SECONDS . 's)';
        }

        $this->warnWindow[$key] = ['at' => $now, 'suppressed' => 0];

        RelayLog::warning($message);
    }
}
