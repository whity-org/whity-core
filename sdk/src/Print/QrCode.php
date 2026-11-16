<?php

declare(strict_types=1);

namespace Whity\Sdk\Print;

/**
 * A QR Code encoder for byte data (ISO/IEC 18004), for any plugin that prints
 * or shows a code without a vendor package (a code plugin ships no vendor/).
 * It came from Front Office's receipt, which carries the JoFotara QR (SDK 1.111,
 * WHIT-1060).
 *
 * A port of Project Nayuki's QR Code generator (MIT licence,
 * https://www.nayuki.io/page/qr-code-generator-library), cut down to byte
 * mode: picks the smallest version for the error-correction level, then the
 * mask with the lowest penalty. core's `tests/Sdk/Print/QrCodeTest.php` checks the
 * modules against an independent encoder.
 */
final class QrCode
{
    public const ECC_L = 0;
    public const ECC_M = 1;
    public const ECC_Q = 2;
    public const ECC_H = 3;

    /** Format bits per level (L, M, Q, H). */
    private const ECC_FORMAT_BITS = [1, 0, 3, 2];

    private const ECC_CODEWORDS_PER_BLOCK = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];

    private const NUM_ERROR_CORRECTION_BLOCKS = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];

    public readonly int $version;
    public readonly int $size;
    public readonly int $mask;

    /** @var list<list<bool>> */
    private array $modules = [];
    /** @var list<list<bool>> */
    private array $isFunction = [];

    /** Encodes bytes at the given level, in the smallest version that holds them. */
    public static function encode(string $data, int $ecl = self::ECC_M, int $forceMask = -1): self
    {
        $len = strlen($data);
        for ($version = 1; ; $version++) {
            $countBits = $version < 10 ? 8 : 16;
            $capacityBits = self::numDataCodewords($version, $ecl) * 8;
            $used = 4 + $countBits + $len * 8;
            if ($len < (1 << $countBits) && $used <= $capacityBits) {
                break;
            }
            if ($version >= 40) {
                throw new \LengthException('Too much data for a QR code');
            }
        }

        $bits = [];
        self::appendBits(0x4, 4, $bits);
        self::appendBits($len, $countBits, $bits);
        for ($i = 0; $i < $len; $i++) {
            self::appendBits(ord($data[$i]), 8, $bits);
        }
        $capacityBits = self::numDataCodewords($version, $ecl) * 8;
        self::appendBits(0, min(4, $capacityBits - count($bits)), $bits);
        self::appendBits(0, (8 - count($bits) % 8) % 8, $bits);
        for ($pad = 0xEC; count($bits) < $capacityBits; $pad ^= 0xEC ^ 0x11) {
            self::appendBits($pad, 8, $bits);
        }

        $codewords = array_fill(0, intdiv(count($bits), 8), 0);
        foreach ($bits as $i => $bit) {
            $codewords[$i >> 3] |= $bit << (7 - ($i & 7));
        }

        return new self($version, $ecl, $codewords, $forceMask);
    }

    /** @param list<int> $dataCodewords */
    private function __construct(int $version, private readonly int $ecl, array $dataCodewords, int $forceMask)
    {
        $this->version = $version;
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);

        $this->drawFunctionPatterns();
        $all = $this->addEccAndInterleave($dataCodewords);
        $this->drawCodewords($all);

        if ($forceMask === -1) {
            $best = PHP_INT_MAX;
            for ($i = 0; $i < 8; $i++) {
                $this->applyMask($i);
                $this->drawFormatBits($i);
                $penalty = $this->penaltyScore();
                if ($penalty < $best) {
                    $forceMask = $i;
                    $best = $penalty;
                }
                $this->applyMask($i);
            }
        }
        $this->mask = $forceMask;
        $this->applyMask($forceMask);
        $this->drawFormatBits($forceMask);
        $this->isFunction = [];
    }

    public function module(int $x, int $y): bool
    {
        return $x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size && $this->modules[$y][$x];
    }

    /** The code as SVG, one unit per module, with a quiet zone of `$border` modules. */
    public function toSvg(int $border = 4): string
    {
        $parts = [];
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->modules[$y][$x]) {
                    $parts[] = sprintf('M%d,%dh1v1h-1z', $x + $border, $y + $border);
                }
            }
        }
        $dim = $this->size + $border * 2;

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 %1$d %1$d" shape-rendering="crispEdges"><rect width="100%%" height="100%%" fill="#FFFFFF"/><path d="%2$s" fill="#000000"/></svg>',
            $dim,
            implode(' ', $parts),
        );
    }

    public function toDataUri(int $border = 4): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($this->toSvg($border));
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }
        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $positions = $this->alignmentPositions();
        $n = count($positions);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                if (!(($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0))) {
                    $this->drawAlignment($positions[$i], $positions[$j]);
                }
            }
        }
        $this->drawFormatBits(0);
        $this->drawVersion();
    }

    private function drawFormatBits(int $mask): void
    {
        $data = self::ECC_FORMAT_BITS[$this->ecl] << 3 | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = ($data << 10 | $rem) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, self::bit($bits, $i));
        }
        $this->setFunction(8, 7, self::bit($bits, 6));
        $this->setFunction(8, 8, self::bit($bits, 7));
        $this->setFunction(7, 8, self::bit($bits, 8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, self::bit($bits, $i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, self::bit($bits, $i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, self::bit($bits, $i));
        }
        $this->setFunction(8, $this->size - 8, true);
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = $this->version << 12 | $rem;
        for ($i = 0; $i < 18; $i++) {
            $bit = self::bit($bits, $i);
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $bit);
            $this->setFunction($b, $a, $bit);
        }
    }

    private function drawFinder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $dist = max(abs($dx), abs($dy));
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $this->setFunction($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private function addEccAndInterleave(array $data): array
    {
        $numBlocks = self::NUM_ERROR_CORRECTION_BLOCKS[$this->ecl][$this->version];
        $blockEccLen = self::ECC_CODEWORDS_PER_BLOCK[$this->ecl][$this->version];
        $rawCodewords = intdiv(self::numRawDataModules($this->version), 8);
        $numShortBlocks = $numBlocks - $rawCodewords % $numBlocks;
        $shortBlockLen = intdiv($rawCodewords, $numBlocks);

        $blocks = [];
        $divisor = self::reedSolomonDivisor($blockEccLen);
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $len = $shortBlockLen - $blockEccLen + ($i < $numShortBlocks ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::reedSolomonRemainder($dat, $divisor);
            if ($i < $numShortBlocks) {
                $dat[] = 0;
            }
            $blocks[] = array_merge($dat, $ecc);
        }

        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortBlockLen - $blockEccLen || $j >= $numShortBlocks) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = self::bit($data[$i >> 3], 7 - ($i & 7));
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert && !$this->isFunction[$y][$x]) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    private function penaltyScore(): int
    {
        $result = 0;
        $size = $this->size;
        for ($y = 0; $y < $size; $y++) {
            $color = false;
            $run = 0;
            $history = [0, 0, 0, 0, 0, 0, 0];
            for ($x = 0; $x < $size; $x++) {
                if ($this->modules[$y][$x] === $color) {
                    $run++;
                    if ($run === 5) {
                        $result += 3;
                    } elseif ($run > 5) {
                        $result++;
                    }
                } else {
                    $this->finderPenaltyAddHistory($run, $history);
                    if (!$color) {
                        $result += $this->finderPenaltyCountPatterns($history) * 40;
                    }
                    $color = $this->modules[$y][$x];
                    $run = 1;
                }
            }
            $result += $this->finderPenaltyTerminateAndCount($color, $run, $history) * 40;
        }
        for ($x = 0; $x < $size; $x++) {
            $color = false;
            $run = 0;
            $history = [0, 0, 0, 0, 0, 0, 0];
            for ($y = 0; $y < $size; $y++) {
                if ($this->modules[$y][$x] === $color) {
                    $run++;
                    if ($run === 5) {
                        $result += 3;
                    } elseif ($run > 5) {
                        $result++;
                    }
                } else {
                    $this->finderPenaltyAddHistory($run, $history);
                    if (!$color) {
                        $result += $this->finderPenaltyCountPatterns($history) * 40;
                    }
                    $color = $this->modules[$y][$x];
                    $run = 1;
                }
            }
            $result += $this->finderPenaltyTerminateAndCount($color, $run, $history) * 40;
        }
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $this->modules[$y][$x];
                if ($c === $this->modules[$y][$x + 1] && $c === $this->modules[$y + 1][$x] && $c === $this->modules[$y + 1][$x + 1]) {
                    $result += 3;
                }
            }
        }
        $dark = 0;
        foreach ($this->modules as $row) {
            foreach ($row as $m) {
                $dark += $m ? 1 : 0;
            }
        }
        $total = $size * $size;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;

        return $result + $k * 10;
    }

    /** @param list<int> $history */
    private function finderPenaltyCountPatterns(array $history): int
    {
        $n = $history[1];
        $core = $n > 0 && $history[2] === $n && $history[3] === $n * 3 && $history[4] === $n && $history[5] === $n;

        return ($core && $history[0] >= $n * 4 && $history[6] >= $n ? 1 : 0)
            + ($core && $history[6] >= $n * 4 && $history[0] >= $n ? 1 : 0);
    }

    /** @param list<int> $history */
    private function finderPenaltyTerminateAndCount(bool $color, int $run, array &$history): int
    {
        if ($color) {
            $this->finderPenaltyAddHistory($run, $history);
            $run = 0;
        }
        $run += $this->size;
        $this->finderPenaltyAddHistory($run, $history);

        return $this->finderPenaltyCountPatterns($history);
    }

    /** @param list<int> $history */
    private function finderPenaltyAddHistory(int $run, array &$history): void
    {
        if ($history[0] === 0) {
            $run += $this->size;
        }
        array_pop($history);
        array_unshift($history, $run);
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $num = intdiv($this->version, 7) + 2;
        $step = $this->version === 32 ? 26 : intdiv($this->version * 4 + $num * 2 + 1, $num * 2 - 2) * 2;
        $result = [6];
        for ($pos = $this->size - 7; count($result) < $num; $pos -= $step) {
            array_splice($result, 1, 0, [$pos]);
        }

        return $result;
    }

    private static function numRawDataModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $num = intdiv($version, 7) + 2;
            $result -= (25 * $num - 10) * $num - 55;
            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function numDataCodewords(int $version, int $ecl): int
    {
        return intdiv(self::numRawDataModules($version), 8)
            - self::ECC_CODEWORDS_PER_BLOCK[$ecl][$version] * self::NUM_ERROR_CORRECTION_BLOCKS[$ecl][$version];
    }

    /** @return list<int> */
    private static function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::multiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::multiply($root, 0x02);
        }

        return $result;
    }

    /**
     * @param list<int> $data
     * @param list<int> $divisor
     * @return list<int>
     */
    private static function reedSolomonRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::multiply($coef, $factor);
            }
        }

        return $result;
    }

    private static function multiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z & 0xFF;
    }

    /** @param list<int> $bits */
    private static function appendBits(int $value, int $length, array &$bits): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    private static function bit(int $x, int $i): bool
    {
        return (($x >> $i) & 1) !== 0;
    }
}
