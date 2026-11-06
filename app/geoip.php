<?php
/**
 * País a partir do IP, sem serviços externos: o IP é procurado numa base local e nunca sai do servidor
 * nem fica guardado. Base "IP to Country Lite" da DB-IP (CC BY 4.0), atualizada mensalmente.
 *
 * A base vem como CSV (~730 000 intervalos contíguos e ordenados). Guarda-se em dois ficheiros binários
 * de registos de tamanho fixo [início do intervalo][código do país], ordenados, e procura-se por bisseção
 * (~20 leituras de disco, sem carregar o ficheiro para memória):
 *   data/geoip4.bin  registos de 6 bytes  (4 do IPv4 + 2 do país)  ~2 MB
 *   data/geoip6.bin  registos de 18 bytes (16 do IPv6 + 2 do país) ~7 MB
 */

const GEOIP_URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';

function geoip_file(int $bytes): string
{
    return ROOT . '/data/geoip' . ($bytes === 4 ? '4' : '6') . '.bin';
}

/** Código ISO do país (ex.: "PT") ou '' se desconhecido / IP privado / base não instalada. */
function geoip_country(string $ip): string
{
    $bin = @inet_pton(trim($ip));
    if ($bin === false) {
        return '';
    }
    // IPv4 escrito como IPv6 (::ffff:1.2.3.4) procura-se na tabela IPv4.
    if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
        $bin = substr($bin, 12);
    }
    $len = strlen($bin);
    static $handles = [];
    if (!array_key_exists($len, $handles)) {
        $file = geoip_file($len);
        $handles[$len] = is_file($file) ? @fopen($file, 'rb') : false;
    }
    $fh = $handles[$len];
    if (!$fh) {
        return '';
    }
    $rec = $len + 2;
    $count = intdiv((int) fstat($fh)['size'], $rec);
    // Último registo cujo início é <= ip (os intervalos cobrem todo o espaço de endereços, sem buracos).
    $lo = 0;
    $hi = $count - 1;
    $found = -1;
    while ($lo <= $hi) {
        $mid = ($lo + $hi) >> 1;
        fseek($fh, $mid * $rec);
        $start = (string) fread($fh, $len);
        if (strcmp($start, $bin) <= 0) {
            $found = $mid;
            $lo = $mid + 1;
        } else {
            $hi = $mid - 1;
        }
    }
    if ($found < 0) {
        return '';
    }
    fseek($fh, $found * $rec + $len);
    $cc = (string) fread($fh, 2);
    return preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'ZZ' ? $cc : '';
}

/** ['month' => '2026-11', 'updated' => timestamp, 'ranges' => n] ou null se a base não estiver instalada. */
function geoip_info(): ?array
{
    $meta = ROOT . '/data/geoip.json';
    if (!is_file($meta) || !is_file(geoip_file(4)) || !is_file(geoip_file(16))) {
        return null;
    }
    $info = json_decode((string) file_get_contents($meta), true);
    return is_array($info) ? $info : null;
}

/**
 * Descarrega a base do mês (ou do mês anterior, se a deste ainda não saiu) e substitui a atual.
 * Devolve null se correu bem, ou a mensagem de erro.
 */
function geoip_update(): ?string
{
    @set_time_limit(300);
    $tmp = ROOT . '/data/geoip-download.csv.gz';
    $month = null;
    foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $m) {
        if (geoip_download(sprintf(GEOIP_URL, $m), $tmp)) {
            $month = $m;
            break;
        }
    }
    if ($month === null) {
        @unlink($tmp);
        return 'Não foi possível descarregar a base de países da DB-IP. O alojamento pode bloquear ligações externas.';
    }

    $in = @gzopen($tmp, 'rb');
    $out = [4 => @fopen(geoip_file(4) . '.tmp', 'wb'), 16 => @fopen(geoip_file(16) . '.tmp', 'wb')];
    if (!$in || !$out[4] || !$out[16]) {
        @unlink($tmp);
        return 'Não foi possível escrever na pasta data/.';
    }
    $rows = [4 => 0, 16 => 0];
    $last = [4 => '', 16 => ''];
    $error = null;
    while (($line = gzgets($in)) !== false) {
        $p = explode(',', trim($line));
        if (count($p) < 3) {
            continue;
        }
        $start = @inet_pton($p[0]);
        $cc = strtoupper($p[2]);
        if ($start === false || !preg_match('/^[A-Z]{2}$/', $cc)) {
            continue;
        }
        $len = strlen($start);
        if (strcmp($start, $last[$len]) < 0) {
            $error = 'A base descarregada não está ordenada.';
            break;
        }
        $last[$len] = $start;
        fwrite($out[$len], $start . $cc);
        $rows[$len]++;
    }
    gzclose($in);
    fclose($out[4]);
    fclose($out[16]);
    @unlink($tmp);

    if ($error === null && ($rows[4] < 100000 || $rows[16] < 50000)) {
        $error = 'A base descarregada parece incompleta (' . ($rows[4] + $rows[16]) . ' intervalos).';
    }
    if ($error !== null) {
        @unlink(geoip_file(4) . '.tmp');
        @unlink(geoip_file(16) . '.tmp');
        return $error;
    }
    // rename() substitui de uma vez: os pedidos em curso nunca leem um ficheiro a meio.
    rename(geoip_file(4) . '.tmp', geoip_file(4));
    rename(geoip_file(16) . '.tmp', geoip_file(16));
    file_put_contents(ROOT . '/data/geoip.json', json_encode([
        'month' => $month, 'updated' => time(), 'ranges' => $rows[4] + $rows[16],
    ]));
    return null;
}

function geoip_download(string $url, string $to): bool
{
    if (function_exists('curl_init')) {
        $fh = @fopen($to, 'wb');
        if (!$fh) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_USERAGENT => 'fbnsantos.com',
            CURLOPT_FAILONERROR => true,
        ]);
        $ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($ch);
        fclose($fh);
    } else {
        $ok = @copy($url, $to, stream_context_create(['http' => [
            'timeout' => 180, 'user_agent' => 'fbnsantos.com', 'ignore_errors' => false,
        ]]));
    }
    return $ok && is_file($to) && filesize($to) > 1000000;
}
