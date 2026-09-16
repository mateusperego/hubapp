<?php

namespace HubApp\Relay;

/**
 * O cabeçalho dos frames do canal de espelhamento.
 *
 * O relay lê três campos e mais nada: a flag de keyframe, que diz onde ele pode
 * retomar depois de descartar, e a geração, que entra no log. O corpo é uma
 * access unit H.264 e não é da conta dele — é o mesmo princípio do
 * `Envelope::wrap`, onde o payload vai cru.
 *
 * Layout, 16 bytes big-endian (o contrato completo está em
 * `el_monitor_apps/docs/protocol.md`, seção "Screen mirroring"):
 *
 *   0       magic 0x4D ('M')
 *   1       versão
 *   2       flags: bit0 keyframe · bit1 parameter sets
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
     * 512 KiB.
     *
     * Um IDR de 720p a 2,5 Mbps fica entre 60 e 120 KB, então isto é várias
     * vezes o necessário e ainda metade do `maxPackageSize` do Workerman. Não é
     * o 1 MiB do `Envelope::MAX_BYTES`: aquele teto protege um JSON que o relay
     * precisa decodificar, e aqui ele não decodifica nada.
     */
    public const MAX_BYTES = 524288;

    /** @return array{flags: int, generation: int, sequence: int}|null */
    public static function readHeader(string $frame): ?array
    {
        if (strlen($frame) < self::HEADER_BYTES || strlen($frame) > self::MAX_BYTES) {
            return null;
        }

        if (ord($frame[0]) !== self::MAGIC || ord($frame[1]) !== self::VERSION) {
            return null;
        }

        $fields = unpack('Cflags/x/ngeneration/x2/Nsequence', substr($frame, 2, 10));

        if ($fields === false) {
            return null;
        }

        return [
            'flags'      => $fields['flags'],
            'generation' => $fields['generation'],
            'sequence'   => $fields['sequence'],
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
}
