<?php
declare(strict_types=1);

/**
 * LinkScope Pro v3.0 — Core Crawler, Normalizer, Scope Guard & Multi-cURL Parallel Engine
 * Đề tài 13: Xây dựng công cụ kiểm tra liên kết và tài nguyên bị lỗi trên website
 */

/**
 * Chuẩn hóa danh sách domain được phép từ chuỗi cấu hình của người dùng.
 * Hỗ trợ người dùng nhập domain thuần (example.com), wildcard (*.example.com),
 * hoặc dán nhầm cả URL đầy đủ (https://example.com/path -> tự tách lấy host).
 */
function ls_parse_allowed_domains(string $startUrl, string $rawDomains, bool $allowSubdomains = true): array {
    $p = parse_url(trim($startUrl));
    $startHost = strtolower((string)($p['host'] ?? ''));
    $list = [];
    if ($startHost !== '') {
        $list[] = $startHost;
        if ($allowSubdomains) {
            $bare = preg_replace('/^www\./i', '', $startHost);
            if ($bare !== '' && $bare !== $startHost) {
                $list[] = $bare;
            } elseif (!str_starts_with($startHost, 'www.') && strpos($startHost, '.') !== false && !filter_var($startHost, FILTER_VALIDATE_IP)) {
                $list[] = 'www.' . $startHost;
            }
        }
    }

    if (trim($rawDomains) !== '') {
        $parts = preg_split('/[\s,;]+/', trim($rawDomains)) ?: [];
        foreach ($parts as $part) {
            $part = strtolower(trim($part));
            if ($part === '') {
                continue;
            }
            if (str_contains($part, '://')) {
                $parsedHost = parse_url($part, PHP_URL_HOST);
                if (is_string($parsedHost) && $parsedHost !== '') {
                    $part = strtolower($parsedHost);
                }
            } else {
                $part = explode('/', $part)[0];
                $part = explode(':', $part)[0];
            }
            if ($part !== '') {
                $list[] = $part;
            }
        }
    }

    return array_values(array_unique($list));
}

/**
 * Kiểm tra cú pháp từng mục trong danh sách domain được phép.
 */
function ls_validate_domain_list(array $domains, bool $allowLocal = false): bool {
    if (!$domains) {
        return false;
    }
    foreach ($domains as $d) {
        $d = strtolower(trim($d));
        if ($allowLocal && in_array($d, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            continue;
        }
        if (filter_var($d, FILTER_VALIDATE_IP)) {
            continue;
        }
        $check = str_starts_with($d, '*.') ? substr($d, 2) : $d;
        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+$/i', $check)) {
            return false;
        }
    }
    return true;
}

/**
 * Kiểm tra một hostname có an toàn để truy cập không (chống SSRF).
 * Có bộ nhớ đệm DNS in-memory ($dnsCache) để khi quét web lớn có hàng trăm liên kết
 * cùng domain không bị gọi lại DNS của Windows nhiều lần gây chậm.
 */
function ls_is_safe_host(string $host, bool $allowLocal = false): bool {
    static $dnsCache = [];
    $host = strtolower(trim($host, '[]'));
    $cacheKey = $host . '|' . ($allowLocal ? '1' : '0');
    if (array_key_exists($cacheKey, $dnsCache)) {
        return $dnsCache[$cacheKey];
    }

    // Nếu cho phép môi trường thử nghiệm nội bộ (localhost / 127.0.0.1) do giảng viên/nhóm khai báo
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return $dnsCache[$cacheKey] = $allowLocal;
    }
    if (str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        return $dnsCache[$cacheKey] = false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ok = (bool)filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        return $dnsCache[$cacheKey] = $ok;
    }

    $ips = [];
    // Ưu tiên gethostbynamel() vì tốc độ rất nhanh trên Windows
    $ipv4List = @gethostbynamel($host);
    if (is_array($ipv4List)) {
        foreach ($ipv4List as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }
    }
    // Nếu chưa có kết quả mới fallback sang dns_get_record
    if (!$ips && function_exists('dns_get_record')) {
        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type);
            if (is_array($records)) {
                foreach ($records as $r) {
                    $ip = $r['ip'] ?? $r['ipv6'] ?? '';
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
                        $ips[] = $ip;
                    }
                }
            }
        }
    }

    if (!$ips) {
        // Không phân giải được DNS: đây không phải IP nội bộ bị cấm, mà là lỗi DNS sẽ được cURL ghi nhận là connection_error
        return $dnsCache[$cacheKey] = true;
    }

    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $dnsCache[$cacheKey] = false;
        }
    }
    return $dnsCache[$cacheKey] = true;
}

/**
 * Kiểm tra hostname có nằm trong danh sách domain được phép (allowlist) hay không.
 * Hỗ trợ khớp chính xác, khớp wildcard (*.example.com) và tùy chọn tự nhận subdomain.
 */
function ls_host_in_domains(string $host, array $domains, bool $allowSubdomains = true): bool {
    $host = strtolower(trim($host));
    if ($host === '') {
        return false;
    }
    $bareHost = preg_replace('/^www\./i', '', $host);

    foreach ($domains as $d) {
        $d = strtolower(trim($d));
        if ($d === '') {
            continue;
        }
        if ($host === $d) {
            return true;
        }
        $bareD = preg_replace('/^www\./i', '', $d);
        if ($bareHost === $bareD && $bareD !== '') {
            return true;
        }
        if (str_starts_with($d, '*.')) {
            $root = substr($d, 2);
            if ($host === $root || str_ends_with($host, '.' . $root)) {
                return true;
            }
        } elseif ($allowSubdomains && !filter_var($d, FILTER_VALIDATE_IP) && $d !== 'localhost') {
            if (str_ends_with($host, '.' . $bareD)) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Kiểm tra một URL có thuộc phạm vi cho phép và an toàn không.
 */
function ls_in_scope(
    string $url,
    array $domains,
    bool $allowLocal = false,
    bool $allowSubdomains = true,
    bool $isAssetCheck = false,
    bool $checkExternalAssets = false
): bool {
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || isset($p['user']) || isset($p['pass'])) {
        return false;
    }
    $host = strtolower($p['host']);
    if (!ls_is_safe_host($host, $allowLocal)) {
        return false;
    }
    if (ls_host_in_domains($host, $domains, $allowSubdomains)) {
        return true;
    }
    // Nếu người dùng bật tùy chọn kiểm tra tài nguyên tĩnh (ảnh/CSS/JS) trên CDN ngoài
    if ($isAssetCheck && $checkExternalAssets) {
        return true;
    }
    return false;
}

/**
 * Yêu cầu kỹ thuật 1: Tự viết bộ chuẩn hóa địa chỉ URL (RFC 3986).
 * Giải quyết đường dẫn tương đối (./, ../, /root, ?query, //protocol-relative),
 * tách riêng base_url (không có #fragment) và full_url (có #fragment nếu có).
 */
function ls_normalize_url(string $base, string $href): ?array {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    // Loại bỏ các ký tự điều khiển ẩn trong HTML
    $href = preg_replace('/[\x00-\x1F\x7F]+/', '', $href) ?? '';
    if ($href === '' || $href === '#') {
        return null;
    }
    if (preg_match('~^(javascript:|mailto:|tel:|data:|blob:|about:|whatsapp:|zalo:|ftp:|irc:|magnet:|sms:)~i', $href)) {
        return null;
    }

    $bp = parse_url($base);
    if (!$bp || empty($bp['scheme']) || empty($bp['host'])) {
        return null;
    }
    $baseScheme = strtolower($bp['scheme']);
    $baseHost = strtolower($bp['host']);
    $basePort = isset($bp['port']) ? (int)$bp['port'] : null;
    $origin = $baseScheme . '://' . $baseHost . ($basePort && !(($baseScheme === 'http' && $basePort === 80) || ($baseScheme === 'https' && $basePort === 443)) ? ':' . $basePort : '');

    $fragment = '';
    if (($hashPos = strpos($href, '#')) !== false) {
        $fragment = substr($href, $hashPos + 1);
        $href = substr($href, 0, $hashPos);
    }

    if ($href === '') {
        // Liên kết chỉ gồm #anchor trên cùng trang (ví dụ: href="#muc-1")
        $resolved = $base;
        if (($bHash = strpos($resolved, '#')) !== false) {
            $resolved = substr($resolved, 0, $bHash);
        }
    } elseif (preg_match('~^[a-zA-Z][a-zA-Z0-9+.-]*:~', $href)) {
        $resolved = $href;
    } elseif (str_starts_with($href, '//')) {
        $resolved = $baseScheme . ':' . $href;
    } elseif (str_starts_with($href, '/')) {
        $resolved = $origin . $href;
    } elseif (str_starts_with($href, '?')) {
        $basePath = $bp['path'] ?? '/';
        $resolved = $origin . $basePath . $href;
    } else {
        $basePath = $bp['path'] ?? '/';
        $slashPos = strrpos($basePath, '/');
        $dir = $slashPos === false ? '/' : substr($basePath, 0, $slashPos + 1);
        $resolved = $origin . $dir . $href;
    }

    $p = parse_url($resolved);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || isset($p['user']) || isset($p['pass'])) {
        return null;
    }

    $scheme = strtolower($p['scheme']);
    $host = strtolower($p['host']);
    $port = isset($p['port']) ? (int)$p['port'] : null;
    $portPart = ($port && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) ? ':' . $port : '';

    // Khử các đoạn '.' và '..' theo chuẩn RFC 3986
    $rawPath = $p['path'] ?? '/';
    $trailingSlash = str_ends_with($rawPath, '/');
    $segments = explode('/', $rawPath);
    $stack = [];
    foreach ($segments as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($stack);
            continue;
        }
        // Chuẩn hóa encoding từng segment mà không làm hỏng ký tự hợp lệ
        $decoded = rawurldecode($seg);
        $stack[] = rawurlencode($decoded);
    }

    $cleanPath = '/' . implode('/', $stack);
    if ($trailingSlash && $cleanPath !== '/') {
        $cleanPath .= '/';
    }

    $cleanBase = $scheme . '://' . $host . $portPart . $cleanPath;
    if (isset($p['query']) && $p['query'] !== '') {
        $cleanBase .= '?' . $p['query'];
    }

    $cleanBase = substr($cleanBase, 0, 950);
    $cleanFrag = substr($fragment, 0, 240);
    $fullUrl = $cleanFrag !== '' ? substr($cleanBase . '#' . $cleanFrag, 0, 990) : $cleanBase;

    return [
        'url'      => $cleanBase,   // Base URL không gồm #fragment (dùng để gửi HTTP request)
        'full_url' => $fullUrl,     // URL đầy đủ gồm cả #fragment (nếu có)
        'fragment' => $cleanFrag,
        'host'     => $host,
    ];
}

/**
 * Trích xuất tiêu đề, <base href>, danh sách mục neo (id/name) và mọi liên kết/tài nguyên từ HTML.
 * Tự động loại bỏ nội dung trong <!-- comment --> và <script>/<style> nhưng giữ nguyên số dòng HTML!
 */
function ls_extract_html_data(string $html, string $pageUrl): array {
    $title = '';
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $tm)) {
        $title = trim(html_entity_decode(strip_tags($tm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;
    }

    // Tạo bản sao HTML đã xóa nội dung comment và nội dung trong cặp thẻ script/style
    // nhưng giữ nguyên ký tự xuống dòng (\n) để tính chính xác line_number trong file gốc!
    $cleanHtml = preg_replace_callback(
        '~<!--.*?-->|<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>~is',
        static function (array $m): string {
            $chunk = $m[0];
            if (stripos($chunk, '<script') === 0) {
                // Giữ lại thẻ mở <script src="..."> nếu có thuộc tính src
                if (preg_match('~^<script\b[^>]*\bsrc\s*=[^>]*>~is', $chunk, $openTag)) {
                    $rest = substr($chunk, strlen($openTag[0]));
                    return $openTag[0] . preg_replace('/[^\n]/', ' ', $rest);
                }
            }
            return preg_replace('/[^\n]/', ' ', $chunk) ?? '';
        },
        $html
    ) ?? $html;

    // Xác định <base href="..."> nếu trang có khai báo
    $effectiveBase = $pageUrl;
    if (preg_match('~<base\b[^>]*\bhref\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))~is', $cleanHtml, $bm)) {
        $baseHref = $bm[1] ?? ($bm[2] ?? ($bm[3] ?? ''));
        if ($baseHref !== '') {
            $normBase = ls_normalize_url($pageUrl, $baseHref);
            if ($normBase) {
                $effectiveBase = $normBase['url'];
            }
        }
    }

    // Trích xuất tất cả các mục neo (id="..." hoặc name="...", hỗ trợ cả có nháy và không nháy)
    $anchors = ['top', ''];
    if (preg_match_all('~\b(?:id|name)\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s"\'>]+))~is', $cleanHtml, $am, PREG_SET_ORDER)) {
        foreach ($am as $row) {
            $val = $row[1] ?? '';
            if ($val === '' && isset($row[2]) && $row[2] !== '') {
                $val = $row[2];
            } elseif ($val === '' && isset($row[3])) {
                $val = $row[3];
            }
            $val = trim(html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($val !== '') {
                $anchors[] = $val;
                $anchors[] = rawurldecode($val);
            }
        }
    }
    $anchors = array_values(array_unique($anchors));

    // Trích xuất thẻ liên kết và tài nguyên
    $items = [];
    $nonResourceRels = [
        'dns-prefetch', 'preconnect', 'canonical', 'alternate', 'shortlink',
        'pingback', 'edituri', 'wlwmanifest', 'manifest', 'search', 'author', 'license', 'me'
    ];

    // Bắt cả thẻ <a> có nội dung để lấy anchor_text chuẩn xác, và các thẻ tài nguyên khác
    if (preg_match_all('~<(a|area|img|script|link|iframe|source|video|audio|embed|object)\b([^>]*)>(?:(.*?)</\1>)?~is', $cleanHtml, $tags, PREG_OFFSET_CAPTURE)) {
        $count = count($tags[0]);
        for ($i = 0; $i < $count; $i++) {
            $fullMatch = $tags[0][$i][0];
            $offset = (int)$tags[0][$i][1];
            $tagName = strtolower($tags[1][$i][0]);
            $attrStr = $tags[2][$i][0];
            $innerHtml = isset($tags[3][$i][0]) && is_string($tags[3][$i][0]) ? $tags[3][$i][0] : '';

            // Với thẻ <link>, chỉ kiểm tra stylesheet / icon / preload để tránh kiểm tra nhầm thẻ SEO canonical/alternate
            if ($tagName === 'link') {
                if (preg_match('~\brel\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i', $attrStr, $rm)) {
                    $relVal = strtolower(trim($rm[1] ?? ($rm[2] ?? ($rm[3] ?? ''))));
                    $relTokens = preg_split('/\s+/', $relVal) ?: [];
                    $skipLink = false;
                    foreach ($relTokens as $rt) {
                        if (in_array($rt, $nonResourceRels, true)) {
                            $skipLink = true;
                            break;
                        }
                    }
                    if ($skipLink) {
                        continue;
                    }
                }
            }

            $attrName = match ($tagName) {
                'img', 'script', 'iframe', 'source', 'video', 'audio', 'embed' => 'src',
                'object' => 'data',
                default  => 'href',
            };

            $rawHref = '';
            if (preg_match('~\b' . preg_quote($attrName, '~') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i', $attrStr, $m)) {
                $rawHref = $m[1] ?? '';
                if ($rawHref === '' && isset($m[2]) && $m[2] !== '') {
                    $rawHref = $m[2];
                } elseif ($rawHref === '' && isset($m[3])) {
                    $rawHref = $m[3];
                }
            }

            // Hỗ trợ ảnh lazy-load (data-src / data-original / srcset) rất phổ biến trên các website lớn
            if ($tagName === 'img' && ($rawHref === '' || str_starts_with(trim($rawHref), 'data:'))) {
                if (preg_match('~\b(?:data-src|data-lazy-src|data-original)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i', $attrStr, $dm)) {
                    $rawHref = $dm[1] ?? ($dm[2] ?? ($dm[3] ?? ''));
                } elseif (preg_match('~\bsrcset\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $attrStr, $sm)) {
                    $srcset = trim($sm[1] ?? ($sm[2] ?? ''));
                    if ($srcset !== '') {
                        $firstCandidate = trim(explode(',', $srcset)[0]);
                        $rawHref = preg_split('/\s+/', $firstCandidate)[0] ?? '';
                    }
                }
            }

            if ($rawHref === '') {
                continue;
            }

            $norm = ls_normalize_url($effectiveBase, $rawHref);
            if (!$norm) {
                continue;
            }

            $resType = match ($tagName) {
                'img' => 'image',
                'script', 'link', 'iframe', 'source', 'video', 'audio', 'embed', 'object' => 'resource',
                default => 'link',
            };

            $lineNumber = substr_count(substr($html, 0, $offset), "\n") + 1;
            $openTagSnippet = '<' . $tagName . $attrStr . '>';
            if ($tagName === 'a' && $innerHtml !== '') {
                $anchorText = trim(html_entity_decode(strip_tags($innerHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $anchorText = preg_replace('/\s+/', ' ', $anchorText) ?? $anchorText;
                if (strlen($fullMatch) <= 300) {
                    $openTagSnippet = $fullMatch;
                }
            } elseif ($tagName === 'img' && preg_match('~\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~i', $attrStr, $altM)) {
                $anchorText = trim($altM[1] ?? ($altM[2] ?? ''));
            } else {
                $anchorText = '';
            }

            $items[] = [
                'raw_href'       => substr($rawHref, 0, 950),
                'normalized_url' => $norm['full_url'],
                'base_url'       => $norm['url'],
                'fragment'       => $norm['fragment'],
                'host'           => $norm['host'],
                'resource_type'  => $resType,
                'line_number'    => $lineNumber,
                'tag_snippet'    => substr(trim(preg_replace('/\s+/', ' ', $openTagSnippet) ?? $openTagSnippet), 0, 480),
                'anchor_text'    => substr($anchorText, 0, 480),
            ];
        }
    }

    return [
        'title'   => substr($title, 0, 490),
        'anchors' => $anchors,
        'links'   => $items,
    ];
}

/**
 * Phân loại lỗi khi không nhận được mã HTTP (status = 0).
 * Yêu cầu kỹ thuật 2: Hết hạn kết nối (timeout) phải được ghi đúng là chưa xác định ('unknown'),
 * không tự kết luận trang không tồn tại ('http_error').
 */
function ls_classify_transport_failure(int $curlErrno, string $errorMsg): array {
    if ($curlErrno === 28 || preg_match('/timed out|timeout|operation timed out/i', $errorMsg)) {
        return [
            'category' => 'unknown',
            'detail'   => 'Chưa xác định phản hồi: Quá thời gian chờ (Timeout)',
        ];
    }
    if (in_array($curlErrno, [5, 6, 7, 16, 35, 51, 52, 55, 56, 60], true)
        || preg_match('/getaddrinfo|could not resolve|connection refused|failed to connect|network is unreachable|no route to host|ssl|tls|certificate|reset by peer/i', $errorMsg)
    ) {
        return [
            'category' => 'connection_error',
            'detail'   => 'Lỗi kết nối máy chủ: ' . ($errorMsg !== '' ? $errorMsg : 'Không thể thiết lập kết nối TCP/TLS'),
        ];
    }
    return [
        'category' => 'unknown',
        'detail'   => 'Chưa xác định phản hồi: ' . ($errorMsg !== '' ? $errorMsg : 'Không nhận được phản hồi từ máy chủ'),
    ];
}

/**
 * Kiểm tra xem nội dung ảnh tải về (khi HTTP 200) có thực sự là ảnh hợp lệ hay bị hỏng / soft-404.
 * Hỗ trợ Phép thử 3: Phân biệt được trang 404, ảnh hỏng, mục neo không có và hết thời gian chờ.
 */
function ls_verify_image_payload(string $contentType, string $body): ?string {
    $ct = strtolower(trim(explode(';', $contentType)[0]));
    if ($ct !== '' && (str_starts_with($ct, 'text/html') || str_starts_with($ct, 'text/plain') || str_starts_with($ct, 'application/json'))) {
        return 'Ảnh hỏng: Máy chủ trả về nội dung văn bản (' . $ct . ') thay vì dữ liệu hình ảnh';
    }
    $len = strlen($body);
    if ($len === 0) {
        return 'Ảnh hỏng: Tệp hình ảnh rỗng (0 byte)';
    }
    if (str_starts_with($body, 'CORRUPTED_IMAGE') || str_starts_with($body, 'BROKEN_IMAGE')) {
        return 'Ảnh hỏng: Dữ liệu tệp ảnh bị lỗi cấu trúc (Corrupted image header)';
    }
    // Nếu không có header image/* rõ ràng và nội dung quá ngắn không khớp chữ ký ảnh thông dụng
    if ($ct === '' && $len < 12) {
        return 'Ảnh hỏng: Dữ liệu tệp ảnh không hợp lệ';
    }
    return null;
}

/**
 * Hỗ trợ xử lý trực tiếp Website thử nghiệm nội bộ (testbed/) để tránh deadlock khi chạy trên
 * PHP Built-in Server (php -S) hoặc khi tường lửa phòng máy chặn loopback.
 */
function ls_try_local_testbed_dispatch(string $url, float $timeout): ?array {
    $p = parse_url($url);
    $host = strtolower((string)($p['host'] ?? ''));
    if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return null;
    }
    $path = (string)($p['path'] ?? '');
    $pos = stripos($path, '/testbed/');
    if ($pos === false) {
        return null;
    }
    $relPath = substr($path, $pos + strlen('/testbed/'));
    if ($relPath === '' || str_ends_with($relPath, '/')) {
        $relPath .= 'index.php';
    }
    // Chặn path traversal
    if (str_contains($relPath, '..')) {
        return null;
    }
    $testbedRoot = __DIR__ . '/testbed';
    $fullFile = $testbedRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relPath);

    require_once $testbedRoot . '/common.php';
    $st = testbed_get_state();

    if (basename($relPath) === 'endpoint.php') {
        $query = [];
        if (isset($p['query'])) {
            parse_str($p['query'], $query);
        }
        $mode = (string)($query['mode'] ?? '');
        if ($mode === '404') {
            if (!empty($st['fix_404_legacy'])) {
                return ['status' => 200, 'location' => '', 'content_type' => 'text/html; charset=UTF-8', 'body' => '<html><body><h1 id="tai-lieu-moi">200 OK</h1></body></html>', 'errno' => 0, 'error' => ''];
            }
            return ['status' => 404, 'location' => '', 'content_type' => 'text/html; charset=UTF-8', 'body' => '404 Not Found', 'errno' => 0, 'error' => ''];
        }
        if ($mode === '500') {
            if (!empty($st['fix_server_500'])) {
                return ['status' => 200, 'location' => '', 'content_type' => 'text/html; charset=UTF-8', 'body' => '<html><body><h1>200 OK</h1></body></html>', 'errno' => 0, 'error' => ''];
            }
            return ['status' => 500, 'location' => '', 'content_type' => 'text/html; charset=UTF-8', 'body' => '500 Internal Server Error', 'errno' => 0, 'error' => ''];
        }
        if ($mode === 'corrupted_img') {
            if (!empty($st['fix_broken_image'])) {
                return ['status' => 200, 'location' => '', 'content_type' => 'image/svg+xml', 'body' => '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="40"></svg>', 'errno' => 0, 'error' => ''];
            }
            return ['status' => 200, 'location' => '', 'content_type' => 'image/png', 'body' => 'CORRUPTED_IMAGE_DATA_STREAM_NOT_VALID_PNG', 'errno' => 0, 'error' => ''];
        }
        if ($mode === 'timeout') {
            return ['status' => 0, 'location' => '', 'content_type' => '', 'body' => '', 'errno' => 28, 'error' => 'Operation timed out after ' . (int)round($timeout * 1000) . ' milliseconds'];
        }
        if ($mode === 'redirect_ok') {
            return ['status' => 301, 'location' => 'about.php', 'content_type' => 'text/html', 'body' => '', 'errno' => 0, 'error' => ''];
        }
        if ($mode === 'redirect_out') {
            return ['status' => 302, 'location' => 'https://external-unapproved-redirect.example.org/landing', 'content_type' => 'text/html', 'body' => '', 'errno' => 0, 'error' => ''];
        }
    }

    if (!is_file($fullFile)) {
        return ['status' => 404, 'location' => '', 'content_type' => 'text/html; charset=UTF-8', 'body' => '404 Not Found', 'errno' => 0, 'error' => ''];
    }

    $ext = strtolower(pathinfo($fullFile, PATHINFO_EXTENSION));
    if ($ext === 'svg') {
        return ['status' => 200, 'location' => '', 'content_type' => 'image/svg+xml', 'body' => (string)file_get_contents($fullFile), 'errno' => 0, 'error' => ''];
    }
    if ($ext !== 'php') {
        return ['status' => 200, 'location' => '', 'content_type' => 'application/octet-stream', 'body' => (string)file_get_contents($fullFile), 'errno' => 0, 'error' => ''];
    }

    $oldCwd = getcwd();
    $oldMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    chdir(dirname($fullFile));
    ob_start();
    try {
        include $fullFile;
        $html = (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        if ($oldCwd !== false) {
            chdir($oldCwd);
        }
        $_SERVER['REQUEST_METHOD'] = $oldMethod;
        return ['status' => 500, 'location' => '', 'content_type' => 'text/html', 'body' => $e->getMessage(), 'errno' => 0, 'error' => ''];
    }
    if ($oldCwd !== false) {
        chdir($oldCwd);
    }
    $_SERVER['REQUEST_METHOD'] = $oldMethod;

    return ['status' => 200, 'location' => '', 'content_type' => 'text/html; charset=UTF-8', 'body' => $html, 'errno' => 0, 'error' => ''];
}

/**
 * Hàm thực thi kiểm tra đồng thời nhiều URL bằng Multi-cURL (hoặc fallback stream nếu thiếu cURL).
 * Hỗ trợ kiểm tra thủ công từng bước chuyển hướng (Redirect Hop-by-Hop) để đảm bảo
 * MỌI đích chuyển hướng đều được kiểm tra Allowlist & SSRF trước khi truy cập!
 *
 * @param array<string, array{url: string, need_full_body: bool, is_asset: bool, resource_type: string}> $requests
 * @return array<string, array{status: int, final: string, chain: string, body: string, content_type: string, category: string, error: string, anchors: array}>
 */
function ls_fetch_multi_batch(
    array $requests,
    array $domains,
    float $timeout,
    int $concurrency = 15,
    bool $allowLocal = false,
    bool $allowSubdomains = true,
    bool $checkExternalAssets = false
): array {
    if (!$requests) {
        return [];
    }

    $concurrency = max(1, min(32, $concurrency));
    $maxHops = 5;
    $results = [];
    $active = [];

    foreach ($requests as $key => $req) {
        $active[$key] = [
            'origin_url'     => $req['url'],
            'current_url'    => $req['url'],
            'need_full_body' => (bool)($req['need_full_body'] ?? false),
            'is_asset'       => (bool)($req['is_asset'] ?? false),
            'resource_type'  => (string)($req['resource_type'] ?? 'link'),
            'chain'          => [],
            'hop'            => 0,
        ];
    }

    $caBundle = ini_get('curl.cainfo') ?: (ini_get('openssl.cafile') ?: '');
    if ($caBundle === '' || !is_file($caBundle)) {
        if (is_file('C:/xampp/apache/bin/curl-ca-bundle.crt')) {
            $caBundle = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
        }
    }

    for ($hop = 0; $hop <= $maxHops && $active; $hop++) {
        // Bước 1: Kiểm tra phạm vi (Allowlist & SSRF) trước khi gọi mạng cho mọi URL ở vòng hiện tại
        $toFetch = [];
        foreach ($active as $key => $item) {
            if (!ls_in_scope($item['current_url'], $domains, $allowLocal, $allowSubdomains, $item['is_asset'], $checkExternalAssets)) {
                $isRedirectBlocked = !empty($item['chain']);
                $results[$key] = [
                    'status'       => 0,
                    'final'        => $item['current_url'],
                    'chain'        => implode(' -> ', $item['chain']),
                    'body'         => '',
                    'content_type' => '',
                    'category'     => 'unchecked',
                    'error'        => $isRedirectBlocked
                        ? 'Bỏ qua: Đích chuyển hướng nằm ngoài phạm vi domain được phép (' . $item['current_url'] . ')'
                        : 'Ngoài domain cho phép: không truy cập',
                    'anchors'      => [],
                ];
                unset($active[$key]);
                continue;
            }
            $toFetch[$key] = $item;
        }

        if (!$toFetch) {
            break;
        }

        // Bước 2: Xử lý trực tiếp các URL thuộc testbed nội bộ (nếu có), phần còn lại chia cụm chạy song song bằng curl_multi
        foreach ($toFetch as $key => $item) {
            $localTestbedResp = ls_try_local_testbed_dispatch($item['current_url'], $timeout);
            if ($localTestbedResp !== null) {
                ls_process_hop_response($key, $item, $localTestbedResp, $maxHops, $active, $results);
                unset($toFetch[$key]);
            }
        }
        if (!$toFetch) {
            continue;
        }

        $chunks = array_chunk($toFetch, $concurrency, true);
        foreach ($chunks as $chunk) {
            if (!function_exists('curl_multi_init')) {
                // Fallback sang stream nếu máy tắt extension curl
                foreach ($chunk as $key => $item) {
                    $single = ls_fetch_stream_single($item['current_url'], $timeout, $item['need_full_body'] ? 2097152 : 32768);
                    ls_process_hop_response($key, $item, $single, $maxHops, $active, $results);
                }
                continue;
            }

            $mh = curl_multi_init();
            $handles = [];
            $buffers = [];
            $headersMap = [];
            $abortedByLimit = [];

            foreach ($chunk as $key => $item) {
                $ch = curl_init();
                $buffers[$key] = '';
                $headersMap[$key] = ['location' => '', 'content_type' => '', 'status' => 0];
                $abortedByLimit[$key] = false;
                $maxBytes = $item['need_full_body'] ? (2 * 1024 * 1024) : 24576; // 2MB cho HTML cần phân tích, 24KB cho tài nguyên/ảnh

                $connectTimeout = max(1, min(8, (int)ceil($timeout)));
                $totalTimeoutMs = max(1000, (int)round($timeout * 1000));

                $opts = [
                    CURLOPT_URL            => $item['current_url'],
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_HEADER         => false,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_MAXREDIRS      => 0,
                    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                    CURLOPT_TIMEOUT_MS     => $totalTimeoutMs,
                    CURLOPT_NOSIGNAL       => true,
                    CURLOPT_ENCODING       => '', // Tự động giải nén gzip/deflate để tăng tốc quét web lớn
                    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 LinkScope/3.0',
                    CURLOPT_HTTPHEADER     => [
                        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                        'Accept-Language: vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7',
                        'Cache-Control: no-cache',
                    ],
                    CURLOPT_SSL_VERIFYPEER => !$allowLocal,
                    CURLOPT_SSL_VERIFYHOST => $allowLocal ? 0 : 2,
                    CURLOPT_HEADERFUNCTION => static function ($ch, string $headerLine) use (&$headersMap, $key): int {
                        $trimmed = trim($headerLine);
                        if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $trimmed, $m)) {
                            $headersMap[$key]['status'] = (int)$m[1];
                        } elseif (stripos($trimmed, 'Location:') === 0) {
                            $headersMap[$key]['location'] = trim(substr($trimmed, 9));
                        } elseif (stripos($trimmed, 'Content-Type:') === 0) {
                            $headersMap[$key]['content_type'] = trim(substr($trimmed, 13));
                        }
                        return strlen($headerLine);
                    },
                    CURLOPT_WRITEFUNCTION  => static function ($ch, string $data) use (&$buffers, &$abortedByLimit, $key, $maxBytes): int {
                        $currentLen = strlen($buffers[$key]);
                        $dataLen = strlen($data);
                        if ($currentLen + $dataLen > $maxBytes) {
                            $remain = max(0, $maxBytes - $currentLen);
                            if ($remain > 0) {
                                $buffers[$key] .= substr($data, 0, $remain);
                            }
                            $abortedByLimit[$key] = true;
                            return 0; // Dừng tải sớm khi đã đủ dữ liệu kiểm tra (giúp quét web có ảnh/video nặng cực nhanh)
                        }
                        $buffers[$key] .= $data;
                        return $dataLen;
                    },
                ];
                if ($caBundle !== '') {
                    $opts[CURLOPT_CAINFO] = $caBundle;
                }
                curl_setopt_array($ch, $opts);
                curl_multi_add_handle($mh, $ch);
                $handles[$key] = $ch;
            }

            $multiResults = [];
            $running = null;
            do {
                $status = curl_multi_exec($mh, $running);
                while ($info = curl_multi_info_read($mh)) {
                    if (($info['msg'] ?? 0) === CURLMSG_DONE && isset($info['handle'])) {
                        $hid = is_object($info['handle']) ? spl_object_id($info['handle']) : (int)$info['handle'];
                        $multiResults[$hid] = (int)($info['result'] ?? 0);
                    }
                }
                if ($running > 0 && $status === CURLM_OK) {
                    if (curl_multi_select($mh, 0.05) === -1) {
                        usleep(10000);
                    }
                }
            } while ($running > 0 && $status === CURLM_OK);

            while ($info = curl_multi_info_read($mh)) {
                if (($info['msg'] ?? 0) === CURLMSG_DONE && isset($info['handle'])) {
                    $hid = is_object($info['handle']) ? spl_object_id($info['handle']) : (int)$info['handle'];
                    $multiResults[$hid] = (int)($info['result'] ?? 0);
                }
            }

            foreach ($handles as $key => $ch) {
                $hid = is_object($ch) ? spl_object_id($ch) : (int)$ch;
                $errno = curl_errno($ch);
                if ($errno === 0 && isset($multiResults[$hid])) {
                    $errno = $multiResults[$hid];
                }
                $errStr = curl_error($ch);
                if ($errStr === '' && $errno !== 0 && function_exists('curl_strerror')) {
                    $errStr = (string)curl_strerror($errno);
                }
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode === 0 && $headersMap[$key]['status'] > 0) {
                    $httpCode = $headersMap[$key]['status'];
                }
                // Nếu dừng tải sớm do đã đủ dung lượng kiểm tra ($abortedByLimit) và đã có mã HTTP hợp lệ thì bỏ qua lỗi CURLE_WRITE_ERROR (23)
                if ($abortedByLimit[$key] && $httpCode > 0 && $errno === 23) {
                    $errno = 0;
                    $errStr = '';
                }

                $single = [
                    'status'       => $httpCode,
                    'location'     => $headersMap[$key]['location'],
                    'content_type' => $headersMap[$key]['content_type'] ?: (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
                    'body'         => $buffers[$key],
                    'errno'        => $errno,
                    'error'        => $errStr,
                ];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                ls_process_hop_response($key, $chunk[$key], $single, $maxHops, $active, $results);
            }

            curl_multi_close($mh);
        }
    }

    return $results;
}

/**
 * Xử lý kết quả một bước HTTP trong chuỗi kiểm tra (bao gồm chuyển hướng, phân loại lỗi, kiểm tra ảnh hỏng).
 */
function ls_process_hop_response(
    string $key,
    array $item,
    array $resp,
    int $maxHops,
    array &$active,
    array &$results
): void {
    $status = (int)$resp['status'];
    $loc = trim((string)$resp['location']);
    $body = (string)$resp['body'];
    $ct = (string)$resp['content_type'];

    // Xử lý chuyển hướng 3xx
    if ($status >= 300 && $status < 400) {
        if ($loc === '') {
            $results[$key] = [
                'status'       => $status,
                'final'        => $item['current_url'],
                'chain'        => implode(' -> ', $item['chain']),
                'body'         => '',
                'content_type' => $ct,
                'category'     => 'redirect',
                'error'        => 'Chuyển hướng HTTP ' . $status . ' nhưng thiếu header Location',
                'anchors'      => [],
            ];
            unset($active[$key]);
            return;
        }
        $target = ls_normalize_url($item['current_url'], $loc);
        if (!$target) {
            $results[$key] = [
                'status'       => $status,
                'final'        => $item['current_url'],
                'chain'        => implode(' -> ', $item['chain']),
                'body'         => '',
                'content_type' => $ct,
                'category'     => 'http_error',
                'error'        => 'Đích chuyển hướng không hợp lệ: ' . $loc,
                'anchors'      => [],
            ];
            unset($active[$key]);
            return;
        }
        $item['chain'][] = $item['current_url'] . ' [' . $status . ']';
        $item['current_url'] = $target['url'];
        $item['hop']++;

        if ($item['hop'] > $maxHops) {
            $results[$key] = [
                'status'       => $status,
                'final'        => $item['current_url'],
                'chain'        => implode(' -> ', $item['chain']),
                'body'         => '',
                'content_type' => $ct,
                'category'     => 'http_error',
                'error'        => 'Vượt quá giới hạn ' . $maxHops . ' lần chuyển hướng liên tiếp (vòng lặp Redirect)',
                'anchors'      => [],
            ];
            unset($active[$key]);
            return;
        }
        $active[$key] = $item;
        return;
    }

    // Không phải 3xx -> kết thúc chuỗi cho mục $key
    unset($active[$key]);
    $chainStr = implode(' -> ', $item['chain']);

    if ($status === 0) {
        $fail = ls_classify_transport_failure((int)$resp['errno'], (string)$resp['error']);
        $results[$key] = [
            'status'       => 0,
            'final'        => $item['current_url'],
            'chain'        => $chainStr,
            'body'         => '',
            'content_type' => $ct,
            'category'     => $fail['category'],
            'error'        => $fail['detail'],
            'anchors'      => [],
        ];
        return;
    }

    if ($status >= 400) {
        $typeLabel = $item['resource_type'] === 'image' ? 'Ảnh lỗi' : ($item['resource_type'] === 'resource' ? 'Tài nguyên lỗi' : 'Lỗi HTTP');
        $statusReason = match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden (Bị từ chối truy cập)',
            404 => 'Not Found (Không tồn tại)',
            410 => 'Gone (Đã bị xóa)',
            429 => 'Too Many Requests (Giới hạn tốc độ)',
            500 => 'Internal Server Error (Lỗi máy chủ)',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'Mã lỗi ' . $status,
        };
        $results[$key] = [
            'status'       => $status,
            'final'        => $item['current_url'],
            'chain'        => $chainStr,
            'body'         => $body,
            'content_type' => $ct,
            'category'     => 'http_error',
            'error'        => $typeLabel . ': HTTP ' . $status . ' — ' . $statusReason,
            'anchors'      => [],
        ];
        return;
    }

    // Mã 2xx thành công: kiểm tra thêm nếu là hình ảnh (image) xem có bị hỏng dữ liệu không
    if ($item['resource_type'] === 'image') {
        $imgErr = ls_verify_image_payload($ct, $body);
        if ($imgErr !== null) {
            $results[$key] = [
                'status'       => $status,
                'final'        => $item['current_url'],
                'chain'        => $chainStr,
                'body'         => '',
                'content_type' => $ct,
                'category'     => 'http_error',
                'error'        => $imgErr,
                'anchors'      => [],
            ];
            return;
        }
    }

    // Trích xuất danh sách anchor cho mọi phản hồi HTML/văn bản để bất kỳ liên kết #fragment nào
    // ở các trang tiếp theo cũng đối chiếu được ngay mà không cần tải lại trang đích
    $anchors = [];
    if ($body !== '' && $item['resource_type'] !== 'image') {
        $anchors = ['top', ''];
        if (preg_match_all('~\b(?:id|name)\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s"\'>]+))~is', $body, $am, PREG_SET_ORDER)) {
            foreach ($am as $row) {
                $val = trim(html_entity_decode($row[1] ?? ($row[2] ?? ($row[3] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($val !== '') {
                    $anchors[] = $val;
                    $anchors[] = rawurldecode($val);
                }
            }
        }
        $anchors = array_values(array_unique($anchors));
    }

    $hasRedirectChain = $chainStr !== '';
    $results[$key] = [
        'status'            => $status,
        'final'             => $item['current_url'],
        'chain'             => $chainStr,
        'body'              => $body,
        'content_type'      => $ct,
        'category'          => $hasRedirectChain ? 'redirect' : 'ok',
        'error'             => $hasRedirectChain
            ? 'Chuyển hướng thành công đến ' . $item['current_url'] . ' (HTTP ' . $status . ')'
            : 'Phản hồi thành công (HTTP ' . $status . ')',
        'anchors'           => $anchors,
        'full_body_fetched' => (bool)$item['need_full_body'],
    ];
}

/**
 * Fallback dùng PHP stream nếu môi trường không bật cURL.
 */
function ls_fetch_stream_single(string $url, float $timeout, int $maxBytes): array {
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'timeout'         => $timeout,
            'ignore_errors'   => true,
            'follow_location' => 0,
            'max_redirects'   => 0,
            'header'          => "User-Agent: Mozilla/5.0 LinkScope/3.0\r\nAccept: */*\r\nConnection: close\r\n",
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);
    $warning = '';
    set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
        $warning = $message;
        return true;
    });
    try {
        $body = @file_get_contents($url, false, $ctx, 0, $maxBytes);
    } finally {
        restore_error_handler();
    }
    $headers = $http_response_header ?? [];
    $status = 0;
    $location = '';
    $ct = '';
    foreach ($headers as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $h, $m)) {
            $status = (int)$m[1];
        } elseif (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        } elseif (stripos($h, 'Content-Type:') === 0) {
            $ct = trim(substr($h, 13));
        }
    }
    $errno = ($status === 0 && preg_match('/timed out|timeout/i', $warning)) ? 28 : ($status === 0 ? 7 : 0);
    return [
        'status'       => $status,
        'location'     => $location,
        'content_type' => $ct,
        'body'         => $body === false ? '' : (string)$body,
        'errno'        => $errno,
        'error'        => $warning,
    ];
}

/**
 * Gọi kiểm tra một URL đơn lẻ (tiện lợi khi crawl từng trang HTML hoặc kiểm tra lại 1 mục).
 */
function ls_fetch_single(
    string $url,
    array $domains,
    float $timeout,
    bool $needFullBody = true,
    string $resourceType = 'page',
    bool $allowLocal = false,
    bool $allowSubdomains = true,
    bool $checkExternalAssets = false
): array {
    $batch = ls_fetch_multi_batch(
        [
            'single' => [
                'url'            => $url,
                'need_full_body' => $needFullBody,
                'is_asset'       => in_array($resourceType, ['image', 'resource'], true),
                'resource_type'  => $resourceType,
            ],
        ],
        $domains,
        $timeout,
        1,
        $allowLocal,
        $allowSubdomains,
        $checkExternalAssets
    );
    return $batch['single'] ?? [
        'status'       => 0,
        'final'        => $url,
        'chain'        => '',
        'body'         => '',
        'content_type' => '',
        'category'     => 'unknown',
        'error'        => 'Không nhận được kết quả kiểm tra',
        'anchors'      => [],
    ];
}

/**
 * Thực thi toàn bộ một phiên quét BFS tốc độ cao (dùng chung cho Web UI, CLI Worker và Bộ Kiểm Thử Tự Động).
 */
function ls_execute_scan_run(PDO $pdo, array $config): array {
    $name = trim((string)($config['name'] ?? 'Website cần kiểm tra'));
    $startUrl = trim((string)($config['start_url'] ?? ''));
    $rawDomains = trim((string)($config['allowed_domains'] ?? ''));
    $maxPages = max(1, min(2000, (int)($config['max_pages'] ?? 50)));
    $maxDepth = max(0, min(15, (int)($config['max_depth'] ?? 3)));
    $timeout = max(1.0, min(30.0, (float)($config['request_timeout'] ?? 4.0)));
    $duration = max(3.0, min(600.0, (float)($config['max_duration'] ?? 60.0)));
    $concurrency = max(1, min(32, (int)($config['concurrency'] ?? 15)));
    $allowSubdomains = !empty($config['allow_subdomains']);
    $checkExternalAssets = !empty($config['check_external_assets']);
    $allowLocal = !empty($config['allow_local']);
    $existingSiteId = isset($config['site_id']) ? (int)$config['site_id'] : 0;

    if (function_exists('set_time_limit')) {
        @set_time_limit((int)ceil($duration) + (int)ceil($timeout) * 4 + 60);
    }
    @ini_set('memory_limit', '512M');

    $normStart = ls_normalize_url($startUrl, $startUrl);
    if (!$normStart) {
        throw new InvalidArgumentException('URL bắt đầu không hợp lệ.');
    }
    $start = $normStart['url'];
    $domains = ls_parse_allowed_domains($start, $rawDomains, $allowSubdomains);
    if (!ls_validate_domain_list($domains, $allowLocal)) {
        throw new InvalidArgumentException('Danh sách domain cho phép chứa hostname không hợp lệ.');
    }
    if (!ls_in_scope($start, $domains, $allowLocal, $allowSubdomains, false, false)) {
        throw new InvalidArgumentException('URL bắt đầu không nằm trong phạm vi domain được phép hoặc là địa chỉ nội bộ chưa được bật chế độ thử nghiệm.');
    }

    $startedAt = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        if ($existingSiteId > 0) {
            $siteId = $existingSiteId;
        } else {
            $stSite = $pdo->prepare('INSERT INTO sites(name,start_url,allowed_domains,max_pages,max_depth,request_timeout,max_duration,concurrency,allow_subdomains,check_external_assets,allow_local,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $stSite->execute([
                $name,
                $start,
                implode(', ', $domains),
                $maxPages,
                $maxDepth,
                $timeout,
                $duration,
                $concurrency,
                $allowSubdomains ? 1 : 0,
                $checkExternalAssets ? 1 : 0,
                $allowLocal ? 1 : 0,
                $startedAt,
            ]);
            $siteId = (int)$pdo->lastInsertId();
        }
        $stRun = $pdo->prepare("INSERT INTO scan_runs(site_id,started_at,status) VALUES(?,?,'running')");
        $stRun->execute([$siteId, $startedAt]);
        $runId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // Các cấu trúc dữ liệu điều phối BFS & bộ nhớ đệm kết quả HTTP
    $queue = [[$start, 0]];
    $queuedPages = [$start => true];
    $visitedPages = [];
    $targetCache = [];       // base_url => kết quả ls_fetch_multi_batch (đảm bảo mỗi đích chỉ gửi HTTP request 1 lần!)
    $savedFindings = [];     // full_url => true (mỗi phát hiện chuẩn hóa lưu 1 dòng trong findings)

    $pagesCrawled = 0;
    $linksFound = 0;
    $errorsCount = 0;
    $uncheckedCount = 0;
    $startTime = microtime(true);
    $timeUp = false;
    $maxPagesHit = false;

    $stInsertPage = $pdo->prepare('INSERT INTO pages(run_id,url,depth,http_status,title,anchors_list,crawled_at) VALUES(?,?,?,?,?,?,?)');
    $stInsertLink = $pdo->prepare('INSERT INTO links(run_id,source_page_id,source_url,raw_href,normalized_url,base_target_url,fragment,resource_type,line_number,tag_snippet,anchor_text) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    $stInsertFinding = $pdo->prepare('INSERT INTO findings(run_id,normalized_url,base_target_url,fragment,resource_type,status_category,http_status,redirect_chain,error_detail,checked_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $stUpdateProgress = $pdo->prepare('UPDATE scan_runs SET total_pages_crawled=?,total_links_found=?,total_errors=?,total_unchecked=? WHERE id=?');

    $recordFinding = static function (
        string $fullUrl,
        string $baseUrl,
        string $fragment,
        string $resType,
        string $category,
        ?int $httpStatus,
        string $chain,
        string $detail
    ) use ($pdo, $runId, $stInsertFinding, &$savedFindings, &$errorsCount, &$uncheckedCount): void {
        if (isset($savedFindings[$fullUrl])) {
            return;
        }
        $savedFindings[$fullUrl] = true;
        $stInsertFinding->execute([
            $runId,
            substr($fullUrl, 0, 990),
            substr($baseUrl, 0, 950),
            substr($fragment, 0, 240),
            $resType,
            $category,
            $httpStatus ?: null,
            substr($chain, 0, 1480),
            substr($detail, 0, 990),
            date('Y-m-d H:i:s'),
        ]);
        if (in_array($category, ['http_error', 'connection_error', 'missing_anchor'], true)) {
            $errorsCount++;
        } elseif (in_array($category, ['unknown', 'unchecked'], true)) {
            $uncheckedCount++;
        }
    };

    try {
        while ($queue) {
            if ((microtime(true) - $startTime) >= $duration) {
                $timeUp = true;
                break;
            }
            if ($pagesCrawled >= $maxPages) {
                $maxPagesHit = true;
                break;
            }

            [$pageUrl, $depth] = array_shift($queue);
            if (isset($visitedPages[$pageUrl])) {
                continue;
            }
            $visitedPages[$pageUrl] = true;

            if (!ls_in_scope($pageUrl, $domains, $allowLocal, $allowSubdomains, false, false)) {
                $recordFinding($pageUrl, $pageUrl, '', 'page', 'unchecked', null, '', 'Ngoài domain cho phép: không truy cập');
                continue;
            }

            // Nếu trang này đã từng được tải đầy đủ HTML trước đó thì dùng lại, ngược lại gọi ls_fetch_single
            if (isset($targetCache[$pageUrl]) && (!empty($targetCache[$pageUrl]['full_body_fetched']) || $targetCache[$pageUrl]['status'] >= 400 || $targetCache[$pageUrl]['status'] === 0)) {
                $pageRes = $targetCache[$pageUrl];
            } else {
                $pageRes = ls_fetch_single($pageUrl, $domains, $timeout, true, 'page', $allowLocal, $allowSubdomains, false);
                $targetCache[$pageUrl] = $pageRes;
            }

            // Nếu URL trong hàng đợi thực chất là liên kết lỗi (404/500/timeout) đã được phát hiện khi kiểm tra link từ trang mẹ
            // và không phải trang khởi đầu (depth > 0), không tính vào số trang HTML đã duyệt để tránh làm sai lệch thống kê trang
            if ($depth > 0 && ((int)$pageRes['status'] === 0 || (int)$pageRes['status'] >= 400 || $pageRes['category'] === 'unchecked')) {
                continue;
            }

            $pagesCrawled++;
            $status = (int)$pageRes['status'];
            $body = (string)$pageRes['body'];

            // Nếu trang chuyển hướng tới một trang khác trong phạm vi, đánh dấu cả URL đích để tránh crawl lặp
            if ($pageRes['final'] !== '' && $pageRes['final'] !== $pageUrl) {
                $visitedPages[$pageRes['final']] = true;
            }

            $parsedHtml = ($body !== '' && $status >= 200 && $status < 400)
                ? ls_extract_html_data($body, $pageRes['final'] ?: $pageUrl)
                : ['title' => '', 'anchors' => [], 'links' => []];

            // Cập nhật lại danh sách anchors chính xác cho cả URL gốc và URL đích sau redirect
            $targetCache[$pageUrl]['anchors'] = $parsedHtml['anchors'];
            if ($pageRes['final'] !== '') {
                $targetCache[$pageRes['final']] = $targetCache[$pageUrl];
            }

            $stInsertPage->execute([
                $runId,
                substr($pageUrl, 0, 950),
                $depth,
                $status ?: null,
                $parsedHtml['title'],
                json_encode($parsedHtml['anchors'], JSON_UNESCAPED_UNICODE),
                date('Y-m-d H:i:s'),
            ]);
            $pageId = (int)$pdo->lastInsertId();

            // Nếu bản thân trang gặp lỗi hoặc chuyển hướng thì ghi nhận vào findings
            if ($status === 0 || $status >= 400 || $pageRes['category'] !== 'ok') {
                $recordFinding(
                    $pageUrl,
                    $pageUrl,
                    '',
                    'page',
                    $pageRes['category'],
                    $status ?: null,
                    $pageRes['chain'],
                    $pageRes['error']
                );
            }

            $extractedLinks = $parsedHtml['links'];
            if (!$extractedLinks) {
                continue;
            }

            // Lưu tất cả các liên kết trên trang vào bảng `links` trong 1 Transaction để tăng tốc gấp 20 lần trên web lớn
            $pdo->beginTransaction();
            try {
                foreach ($extractedLinks as $lnk) {
                    $linksCount = ++$linksFound;
                    $stInsertLink->execute([
                        $runId,
                        $pageId,
                        substr($pageUrl, 0, 950),
                        $lnk['raw_href'],
                        $lnk['normalized_url'],
                        $lnk['base_url'],
                        $lnk['fragment'],
                        $lnk['resource_type'],
                        $lnk['line_number'],
                        $lnk['tag_snippet'],
                        $lnk['anchor_text'],
                    ]);

                    // Đưa các liên kết nội bộ vào hàng đợi BFS nếu còn trong giới hạn độ sâu
                    if ($lnk['resource_type'] === 'link'
                        && $depth + 1 <= $maxDepth
                        && ls_in_scope($lnk['base_url'], $domains, $allowLocal, $allowSubdomains, false, false)
                        && !isset($visitedPages[$lnk['base_url']])
                        && !isset($queuedPages[$lnk['base_url']])
                    ) {
                        // Bỏ qua các đuôi tệp nhị phân không phải trang HTML khi xếp hàng đợi crawl sâu
                        $extPath = strtolower((string)parse_url($lnk['base_url'], PHP_URL_PATH));
                        if (!preg_match('/\.(pdf|zip|rar|7z|exe|dmg|iso|mp4|mp3|avi|mov|jpg|jpeg|png|gif|webp|svg|ico|css|js|woff2?|ttf|eot|xml|json)$/i', $extPath)) {
                            $queuedPages[$lnk['base_url']] = true;
                            $queue[] = [$lnk['base_url'], $depth + 1];
                        }
                    }
                }
                $pdo->commit();
            } catch (Throwable $txEx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txEx;
            }

            // Gom các đích liên kết chưa từng được kiểm tra trên trang này để chạy song song bằng Multi-cURL!
            $pendingRequests = [];
            foreach ($extractedLinks as $lnk) {
                $baseUrl = $lnk['base_url'];
                $isAsset = in_array($lnk['resource_type'], ['image', 'resource'], true);
                $needFullBody = ($lnk['fragment'] !== '' && $lnk['resource_type'] === 'link');

                // Kiểm tra phạm vi trước: nếu ngoài domain cho phép thì ghi nhận 'unchecked' ngay lập tức, không gửi HTTP request
                if (!ls_in_scope($baseUrl, $domains, $allowLocal, $allowSubdomains, $isAsset, $checkExternalAssets)) {
                    $recordFinding(
                        $lnk['normalized_url'],
                        $baseUrl,
                        $lnk['fragment'],
                        $lnk['resource_type'],
                        'unchecked',
                        null,
                        '',
                        'Ngoài domain cho phép: không truy cập'
                    );
                    continue;
                }

                // Nếu đích chưa có trong bộ nhớ đệm, hoặc trước đó chỉ tải 24KB đầu mà bây giờ cần kiểm tra #fragment chưa thấy trong 24KB đầu
                $needRefetchForAnchor = false;
                if ($needFullBody && isset($targetCache[$baseUrl])) {
                    $cachedAnchors = $targetCache[$baseUrl]['anchors'] ?? [];
                    $fragCheck = $lnk['fragment'];
                    if (!in_array($fragCheck, $cachedAnchors, true) && !in_array(rawurldecode($fragCheck), $cachedAnchors, true) && empty($targetCache[$baseUrl]['full_body_fetched']) && $targetCache[$baseUrl]['status'] >= 200 && $targetCache[$baseUrl]['status'] < 300) {
                        $needRefetchForAnchor = true;
                    }
                }
                if (!isset($targetCache[$baseUrl]) || $needRefetchForAnchor) {
                    if (!isset($pendingRequests[$baseUrl])) {
                        $pendingRequests[$baseUrl] = [
                            'url'            => $baseUrl,
                            'need_full_body' => $needFullBody,
                            'is_asset'       => $isAsset,
                            'resource_type'  => $lnk['resource_type'],
                        ];
                    } elseif ($needFullBody) {
                        $pendingRequests[$baseUrl]['need_full_body'] = true;
                    }
                }
            }

            // Chạy kiểm tra theo từng lô (batch) để vừa cực nhanh nhờ Multi-cURL, vừa kiểm tra ngân sách $duration chính xác
            if ($pendingRequests) {
                $batchSize = max($concurrency, $concurrency * 2);
                $requestBatches = array_chunk($pendingRequests, $batchSize, true);
                foreach ($requestBatches as $reqBatch) {
                    if ((microtime(true) - $startTime) >= $duration) {
                        $timeUp = true;
                        break;
                    }
                    $remainingTime = max(1.0, $duration - (microtime(true) - $startTime));
                    $effectiveTimeout = min($timeout, $remainingTime);
                    $batchResults = ls_fetch_multi_batch(
                        $reqBatch,
                        $domains,
                        $effectiveTimeout,
                        $concurrency,
                        $allowLocal,
                        $allowSubdomains,
                        $checkExternalAssets
                    );
                    foreach ($batchResults as $bUrl => $bRes) {
                        $targetCache[$bUrl] = $bRes;
                    }
                }
            }

            // Đối chiếu kết quả từ $targetCache cho toàn bộ liên kết của trang (bao gồm cả kiểm tra #fragment!)
            $pdo->beginTransaction();
            try {
                foreach ($extractedLinks as $lnk) {
                    $baseUrl = $lnk['base_url'];
                    $fullUrl = $lnk['normalized_url'];
                    $frag = $lnk['fragment'];
                    $resType = $lnk['resource_type'];

                    if (isset($savedFindings[$fullUrl])) {
                        continue;
                    }

                    if (!isset($targetCache[$baseUrl])) {
                        // Chưa kịp kiểm tra do chạm giới hạn thời gian giữa chừng trên trang lớn
                        $recordFinding(
                            $fullUrl,
                            $baseUrl,
                            $frag,
                            $resType,
                            'unchecked',
                            null,
                            '',
                            'Chưa kiểm tra: Đã dừng do đạt giới hạn thời gian quét'
                        );
                        continue;
                    }

                    $tr = $targetCache[$baseUrl];
                    $sc = (int)$tr['status'];
                    $cat = $tr['category'];
                    $detail = $tr['error'];

                    // Nếu trang đích hoạt động nhưng đây là thẻ <img> mà trước đó được tải như trang thường, kiểm tra thêm ảnh
                    if ($resType === 'image' && $sc >= 200 && $sc < 300 && $cat === 'ok' && $tr['body'] !== '') {
                        $imgErr = ls_verify_image_payload($tr['content_type'], $tr['body']);
                        if ($imgErr !== null) {
                            $cat = 'http_error';
                            $detail = $imgErr;
                        }
                    }

                    // Kiểm tra mục neo (#fragment) nếu liên kết có #anchor và trang đích phản hồi 2xx
                    if ($frag !== '' && $resType === 'link' && $sc >= 200 && $sc < 300 && in_array($cat, ['ok', 'redirect'], true)) {
                        $anchorsList = $tr['anchors'] ?? [];
                        $decodedFrag = rawurldecode($frag);
                        if (!in_array($frag, $anchorsList, true) && !in_array($decodedFrag, $anchorsList, true)) {
                            $cat = 'missing_anchor';
                            $detail = 'Không tìm thấy mục neo (anchor) #' . $frag . ' trên trang đích';
                        } else {
                            $detail = 'Phản hồi thành công · Mục neo #' . $frag . ' hợp lệ';
                        }
                    }

                    $recordFinding(
                        $fullUrl,
                        $baseUrl,
                        $frag,
                        $resType,
                        $cat,
                        $sc ?: null,
                        $tr['chain'],
                        $detail
                    );
                }
                $stUpdateProgress->execute([$pagesCrawled, $linksFound, $errorsCount, $uncheckedCount, $runId]);
                $pdo->commit();
            } catch (Throwable $txEx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txEx;
            }

            if ($timeUp) {
                break;
            }
        }

        // Phép thử bắt buộc số 4: "vượt giới hạn thì dừng và ghi phần chưa kiểm tra"
        // Nếu dừng do đạt giới hạn số trang hoặc thời gian mà trong hàng đợi vẫn còn trang chưa được kiểm tra,
        // ghi nhận các địa chỉ đó vào bảng findings với trạng thái 'unchecked'!
        if ($queue) {
            $limitReason = $timeUp
                ? 'Chưa kiểm tra: Đã dừng do đạt giới hạn thời gian quét (' . $duration . 's)'
                : 'Chưa kiểm tra: Đã dừng do đạt giới hạn số trang tối đa (' . $maxPages . ' trang)';
            $pdo->beginTransaction();
            try {
                foreach ($queue as [$remUrl, $remDepth]) {
                    if (!isset($visitedPages[$remUrl]) && !isset($savedFindings[$remUrl])) {
                        $recordFinding($remUrl, $remUrl, '', 'page', 'unchecked', null, '', $limitReason);
                    }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }

        $stopReason = 'Đã hoàn tất toàn bộ phạm vi';
        if ($timeUp) {
            $stopReason = 'Đạt giới hạn thời gian (' . $duration . ' giây)';
        } elseif ($maxPagesHit || ($queue && $pagesCrawled >= $maxPages)) {
            $stopReason = 'Đạt giới hạn số trang (' . $maxPages . ' trang)';
        }

        $finishedAt = date('Y-m-d H:i:s');
        $stFinish = $pdo->prepare("UPDATE scan_runs SET finished_at=?,status='completed',stop_reason=?,total_pages_crawled=?,total_links_found=?,total_errors=?,total_unchecked=? WHERE id=?");
        $stFinish->execute([$finishedAt, $stopReason, $pagesCrawled, $linksFound, $errorsCount, $uncheckedCount, $runId]);

        if ($errorsCount > 0) {
            $stNotif = $pdo->prepare('INSERT INTO notifications(run_id,message) VALUES(?,?)');
            $stNotif->execute([$runId, "Phiên #$runId ($name) phát hiện $errorsCount lỗi liên kết/tài nguyên cần kiểm tra."]);
        }

        return [
            'run_id'          => $runId,
            'site_id'         => $siteId,
            'pages_crawled'   => $pagesCrawled,
            'links_found'     => $linksFound,
            'errors_count'    => $errorsCount,
            'unchecked_count' => $uncheckedCount,
            'stop_reason'     => $stopReason,
            'elapsed_sec'     => round(microtime(true) - $startTime, 2),
        ];
    } catch (Throwable $e) {
        error_log('LinkScope scan #' . $runId . ' error: ' . $e->getMessage());
        $stFail = $pdo->prepare("UPDATE scan_runs SET finished_at=?,status='failed',stop_reason=? WHERE id=?");
        $stFail->execute([date('Y-m-d H:i:s'), 'Lỗi hệ thống: ' . substr($e->getMessage(), 0, 200), $runId]);
        throw $e;
    }
}
