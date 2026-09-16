<?php

namespace HubApp\Relay;

/**
 * O cabeçalho dos frames do canal de espelhamento.
 *
 * O relay lê o cabeçalho e mais nada: as flags, que dizem onde ele pode retomar
 * depois de descartar e o que é seguro descartar; a geração, a sequência e o
 * relógio da sessão, que são o que ele tem para medir a saúde do enlace. O corpo
 * é uma access unit H.264 e não é da conta dele — é o mesmo princípio do
 * `Envelope::wrap`, onde o payload vai cru.
 *
 * Layout, 16 bytes big-endian (o contrato completo está em
 * `el_monitor_apps/docs/protocol.md`, seção "Screen mirroring"):
 *
 *   0       magic 0x4D ('M')
 *   1       versão
 *   2       flags: bit0 keyframe · bit1 parameter sets · bit2 descartável
 *   3       reservado
 *   4..5    uint16  generation
 *   6..7    reservado
 *   8..11   uint32  sequência
 *   12..15  uint32  ms desde o início da sessão
 *   16..    a access unit, Annex-B
 */
class MirrorFrame
{
    public const HEADER_BYTES = 16;

    public const MAGIC   = 0x4D;
    public const VERSION = 1;

    public const FLAG_KEYFRAME       = 0x01;
    public const FLAG_PARAMETER_SETS = 0x02;

    /**
     * O frame que pode ser descartado sozinho, sem estragar os seguintes.
     *
     * Um frame que ninguém referencia (camada temporal alta, `nal_ref_idc == 0`)
     * pode sair do fluxo sem consequência: o próximo se decodifica igual. É o
     * que permite degradar o FPS suavemente em vez de congelar a imagem até o
     * próximo IDR, que é o que acontece quando o relay descarta um frame de
     * referência.
     *
     * O aparelho é quem sabe — só ele conhece a estrutura que o encoder montou.
     * Enquanto ele não marcar nada, este bit nunca chega e o relay se comporta
     * exatamente como antes: todo frame conta como referência.
     */
    public const FLAG_DISPOSABLE = 0x04;

    /**
     * 512 KiB.
     *
     * Um IDR de 720p a 2,5 Mbps fica entre 60 e 120 KB, então isto é várias
     * vezes o necessário. Não é o 1 MiB do `Envelope::MAX_BYTES`: aquele teto
     * protege um JSON que o relay precisa decodificar, e aqui ele não decodifica
     * nada.
     */
    public const MAX_BYTES = 524288;

    /**
     * @return array{flags: int, generation: int, sequence: int, elapsed: int}|null
     */
    public static function readHeader(string $frame): ?array
    {
        if (strlen($frame) < self::HEADER_BYTES || strlen($frame) > self::MAX_BYTES) {
            return null;
        }

        if (ord($frame[0]) !== self::MAGIC || ord($frame[1]) !== self::VERSION) {
            return null;
        }

        // Os 14 bytes que sobram depois do magic e da versão. O `elapsed` entra
        // aqui porque é de graça — já está no frame — e é o único jeito de o
        // relay saber a que cadência a fonte está produzindo de verdade, em vez
        // de acreditar no que o aparelho relata.
        $fields = unpack('Cflags/x/ngeneration/x2/Nsequence/Nelapsed', substr($frame, 2, 14));

        if ($fields === false) {
            return null;
        }

        return [
            'flags'      => $fields['flags'],
            'generation' => $fields['generation'],
            'sequence'   => $fields['sequence'],
            'elapsed'    => $fields['elapsed'],
        ];
    }

    public static function isKeyframe(array $header): bool
    {
        return ($header['flags'] & self::FLAG_KEYFRAME) !== 0;
    }

    /**
     * Frame que carrega só SPS/PPS.
     *
     * São centenas de bytes e são entregues em qualquer estado, inclusive no
     * meio de um descarte: sem eles o keyframe seguinte é inútil, e guardá-los
     * é o que permite a um segundo painel entrar numa sessão em andamento sem
     * coordenação nenhuma.
     */
    public static function isParameterSets(array $header): bool
    {
        return ($header['flags'] & self::FLAG_PARAMETER_SETS) !== 0;
    }

    public static function isDisposable(array $header): bool
    {
        return ($header['flags'] & self::FLAG_DISPOSABLE) !== 0;
    }
}
