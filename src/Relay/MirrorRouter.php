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
 * é obrigatório e não uma otimização.
 */
class MirrorRouter
{
    private MirrorRegistry $mirrors;
    private ConnectionRegistry $registry;

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
            RelayLog::warning(
                'frame de espelhamento inválido na sessão '
                . ($this->mirrors->session($key)['session_id'] ?? '?')
            );
            return;
        }

        $keyframe = MirrorFrame::isKeyframe($header);

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
            // aqui é só não gastar trabalho.
            return;
        }

        $askKey = false;

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

            if ($state !== 'flowing') {
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

                if ($this->mirrors->countDrop($key, $sink) === 1) {
                    $askKey = true;
                }
                continue;
            }

            if ($queue > $this->mirrors->highWater()) {
                $this->mirrors->setState($key, $sink, 'dropping');
                $this->mirrors->countDrop($key, $sink);
                $askKey = true;

                RelayLog::warning(
                    'painel lento no espelhamento '
                    . ($this->mirrors->session($key)['session_id'] ?? '?')
                    . ": {$queue} bytes na fila; descartando até o keyframe"
                );
                continue;
            }

            $sink->send($frame);
            $this->mirrors->countForwarded($key);
        }

        if ($askKey) {
            $this->askKeyframe($key, 'congestion');
        }
    }

    /**
     * Pede um IDR ao aparelho pelo canal de **controle**.
     *
     * Não pelo socket de mídia: aquele é binário e de mão única, e inventar um
     * comando reverso nele seria criar um segundo protocolo para dizer uma
     * frase. Este é o único ponto em que o relay escreve uma mensagem de
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
}
