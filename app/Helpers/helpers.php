<?php

if (!function_exists('formatRupiah')) {
    /**
     * Format angka ke Rupiah
     *
     * @param float|int $amount
     * @param bool $withPrefix
     * @return string
     */
    function formatRupiah($amount, $withPrefix = true)
    {
        $prefix = $withPrefix ? 'Rp ' : '';
        return $prefix . number_format($amount, 0, ',', '.');
    }
}
