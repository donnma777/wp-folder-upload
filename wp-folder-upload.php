<?php
/**
 * Plugin Name: Folder Upload
 * Description: uploads の中の好きなフォルダへ、ファイルやフォルダごとアップロードします（管理者のみ）。メディアライブラリへの登録も選べます。
 * Version: 1.6.0
 * Author: donnma
 * Author URI: https://donnma.com/
 * Plugin URI: https://github.com/donnma777/wp-folder-upload
 * License: GPLv2 or later
 * Requires PHP: 8.0
 *
 * 安全のための約束（ここを緩めないこと）
 *   1. 置き場所は wp-content/uploads の中だけ。フォルダ名は英数字・-・_ だけにし、実際のパスでも外に出ていないか確かめる
 *   2. ファイルは拡張子の許可リストに加えて、中身（finfo の MIME・画像なら getimagesize）でも確かめる
 *   3. 管理者（manage_options）だけ。送信のたびに nonce を確かめる
 */

if (!defined('ABSPATH')) exit;

/** 受け付けるファイル：拡張子 => 中身として許す MIME */
function dfu_allowed_types(): array {
	return [
		'jpg'   => ['image/jpeg'],
		'jpeg'  => ['image/jpeg'],
		'png'   => ['image/png'],
		'gif'   => ['image/gif'],
		'webp'  => ['image/webp'],
		'avif'  => ['image/avif', 'image/heif'],
		'ico'   => ['image/vnd.microsoft.icon', 'image/x-icon'],
		'pdf'   => ['application/pdf'],
		'mp4'   => ['video/mp4'],
		'webm'  => ['video/webm'],
		'mp3'   => ['audio/mpeg'],
		'woff'  => ['font/woff', 'application/font-woff'],
		'woff2' => ['font/woff2', 'application/font-woff2'],
		'ttf'   => ['font/sfnt', 'font/ttf', 'application/x-font-ttf', 'application/font-sfnt'],
		'otf'   => ['font/otf', 'font/sfnt', 'application/vnd.ms-opentype', 'application/x-font-otf'],
		'css'   => ['text/plain', 'text/css'],
		'svg'   => ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'],   // 中身は dfu_check_svg で厳しく見る
	];
}

/** 画像として中身まで確かめる拡張子（getimagesize が通ること） */
function dfu_image_exts(): array {
	return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
}

/** WP が作った縮小版（photo-150x150.jpg）か。元の photo.* が $set（array_flip した名前の一覧）にあるときだけ true */
function dfu_is_resized(string $n, array $set): bool {
	if (!preg_match('/^(.+)-\d+x\d+\.(jpe?g|png|gif|webp|avif)$/i', $n, $m)) return false;
	foreach (dfu_image_exts() as $e) {
		if (isset($set[$m[1] . '.' . $e]) || isset($set[$m[1] . '.' . strtoupper($e)])) return true;
	}
	return false;
}

/**
 * フォルダ名をそろえる。'a/b/c' の形（前後の / なし）。だめなら WP_Error
 * 使える文字：英数字・- ・_ ・.（ただし . や .. だけ、. で始まる名前は不可）
 */
function dfu_normalize_folder(string $folder) {
	$folder = str_replace('\\', '/', trim($folder));
	$parts = array_values(array_filter(explode('/', $folder), fn($p) => $p !== ''));
	if (count($parts) > 8) return new WP_Error('dfu_depth', 'フォルダの階層が深すぎます（8段まで）');
	foreach ($parts as $p) {
		if ($p === '.' || $p === '..' || $p[0] === '.' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $p)) {
			return new WP_Error('dfu_folder', 'フォルダ名に使えない文字があります（英数字・-・_・. だけ。. で始まる名前は不可）: ' . $p);
		}
	}
	return implode('/', $parts);
}

/**
 * uploads の中の実際のフォルダを用意して返す。外に出る（シンボリックリンクを含む）ときは WP_Error
 */
function dfu_prepare_dir(string $base, string $rel) {
	$base_real = realpath($base);
	if ($base_real === false) return new WP_Error('dfu_base', 'uploads フォルダが見つかりません');
	$inside = fn($p) => $p === $base_real || strpos($p, $base_real . DIRECTORY_SEPARATOR) === 0;
	// 1段ずつ進む。シンボリックリンクは通らず、作る前に必ず uploads の中にいることを確かめる
	$cur = $base_real;
	foreach ($rel === '' ? [] : explode('/', $rel) as $seg) {
		$next = $cur . '/' . $seg;
		if (is_link($next)) return new WP_Error('dfu_escape', 'uploads の外には置けません（リンクのフォルダ）: ' . $seg);
		if (file_exists($next)) {
			if (!is_dir($next)) return new WP_Error('dfu_mkdir', '同じ名前のファイルがあるので、フォルダを作れません: ' . $seg);
		} elseif (!@mkdir($next, 0755)) {
			return new WP_Error('dfu_mkdir', 'フォルダを作れませんでした: ' . $rel);
		}
		$cur = realpath($next);
		if ($cur === false || !$inside($cur)) return new WP_Error('dfu_escape', 'uploads の外には置けません');
	}
	return $cur;
}

/**
 * ファイル名を確かめる。返り値 [安全な名前, 拡張子] か WP_Error
 */
function dfu_check_name(string $name) {
	$name = sanitize_file_name(wp_basename($name));   // 'a.php.jpg' は 'a.php_.jpg' になる
	if ($name === '' || $name[0] === '.') return new WP_Error('dfu_name', 'ファイル名が正しくありません');
	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	if (!isset(dfu_allowed_types()[$ext])) return new WP_Error('dfu_ext', 'この種類のファイルは置けません: .' . $ext);
	return [$name, $ext];
}

/**
 * 中身を確かめる。拡張子に合う MIME か、画像なら画像として読めるか、テキストに PHP が紛れていないか
 */
function dfu_check_content(string $tmp, string $ext) {
	if (!is_file($tmp) || filesize($tmp) === 0) return new WP_Error('dfu_empty', 'ファイルが空です');
	$mime = '';
	if (function_exists('finfo_open')) {
		$fi = finfo_open(FILEINFO_MIME_TYPE);
		$mime = (string) finfo_file($fi, $tmp);
		finfo_close($fi);
	}
	if ($mime === '' || !in_array($mime, dfu_allowed_types()[$ext], true)) {
		return new WP_Error('dfu_mime', sprintf('中身が .%s のファイルではありません（%s）', $ext, $mime ?: '不明'));
	}
	if (in_array($ext, dfu_image_exts(), true) && @getimagesize($tmp) === false) {
		return new WP_Error('dfu_image', '画像として読めません');
	}
	// PHP のコードを埋め込んだものは受け付けない（テキストのファイルは短い書き方や script も見る。
	// 画像などのバイナリは '<?=' が偶然まじることがあるので '<?php' だけを見る）
	$head = (string) file_get_contents($tmp, false, null, 0, 1024 * 1024);
	$pattern = in_array($ext, ['css', 'svg'], true) ? '/<\?(php|=)|<script\b/i' : '/<\?php/i';
	if (preg_match($pattern, $head)) {
		return new WP_Error('dfu_code', 'ファイルの中にプログラムのコードが含まれています');
	}
	if ($ext === 'svg') {
		$svg = dfu_check_svg($tmp);
		if (is_wp_error($svg)) return $svg;
		return 'image/svg+xml';
	}
	return $mime;
}

/**
 * SVG は「危ないものが1つでもあれば断る」（書き換えて通すことはしない）
 *   - 図形・グラデーション・フィルターなど、決まった要素だけ（script / foreignObject / iframe などは不可）
 *   - on〜 の属性（onload など）は不可。javascript: などは値のどこにあっても不可
 *   - href は同じファイルの中（#id）か、画像の data: だけ。外のファイルは読まない
 *   - DOCTYPE / ENTITY は不可（サーバーのファイルを読ませる手口を防ぐ）。style は @import と外の url() を不可
 */
function dfu_check_svg(string $tmp) {
	if (filesize($tmp) > 2 * 1024 * 1024) return new WP_Error('dfu_svg', 'SVG が大きすぎます（2MB まで）');
	$xml = (string) file_get_contents($tmp);
	if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) return new WP_Error('dfu_svg', 'SVG に DOCTYPE / ENTITY は使えません');
	if (!class_exists('DOMDocument')) return new WP_Error('dfu_svg', 'SVG を確かめられません（DOM が使えません）');
	$prev = libxml_use_internal_errors(true);
	$doc = new DOMDocument();
	$ok = $doc->loadXML($xml, LIBXML_NONET);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);
	if (!$ok || !$doc->documentElement || $doc->documentElement->localName !== 'svg') return new WP_Error('dfu_svg', 'SVG として読めません');
	$svg_ns = 'http://www.w3.org/2000/svg';
	$allowed = array_flip([
		'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata', 'style', 'switch',
		'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
		'text', 'tspan', 'textPath',
		'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask', 'marker', 'image',
		'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feConvolveMatrix', 'feDiffuseLighting',
		'feDisplacementMap', 'feDistantLight', 'feDropShadow', 'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR',
		'feGaussianBlur', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset', 'fePointLight', 'feSpecularLighting',
		'feSpotLight', 'feTile', 'feTurbulence',
	]);
	$bad_value = '/(javascript|vbscript|livescript)\s*:|data\s*:\s*(text|application)\//i';
	$outside_url = '/url\s*\(\s*[\'"]?\s*(?!#|data:image\/)/i';
	foreach ($doc->getElementsByTagName('*') as $el) {
		$ns = (string) $el->namespaceURI;
		// Inkscape などの独自の情報（別の名前空間）は描画に関係しないので通す。XHTML とスクリプトは不可
		if ($ns === $svg_ns || $ns === '') {
			if (!isset($allowed[$el->localName])) return new WP_Error('dfu_svg', 'SVG に使えない要素があります: <' . $el->localName . '>');
		} elseif ($ns === 'http://www.w3.org/1999/xhtml' || strtolower($el->localName) === 'script') {
			return new WP_Error('dfu_svg', 'SVG に HTML やスクリプトは入れられません');
		}
		if ($el->localName === 'style') {
			$css = $el->textContent;
			if (preg_match('/@import|expression\s*\(/i', $css) || preg_match($outside_url, $css) || preg_match($bad_value, $css)) {
				return new WP_Error('dfu_svg', 'SVG の style に外のファイルの読み込みなどがあります');
			}
		}
		foreach ($el->attributes as $a) {
			$name = strtolower($a->localName);
			$val = trim($a->value);
			if (str_starts_with($name, 'on')) return new WP_Error('dfu_svg', 'SVG にイベントの属性（' . $a->nodeName . '）は使えません');
			if (preg_match($bad_value, $val)) return new WP_Error('dfu_svg', 'SVG に使えない値があります（' . $a->nodeName . '）');
			if ($name === 'href' && $val !== '' && $val[0] !== '#' && !preg_match('#^data:image/(png|jpe?g|gif|webp);#i', $val)) {
				return new WP_Error('dfu_svg', 'SVG から外のファイルは読めません（' . $a->nodeName . '）');
			}
			if (preg_match($outside_url, $val)) return new WP_Error('dfu_svg', 'SVG の ' . $a->nodeName . ' に外の url() があります');
		}
	}
	return true;
}

// ------------------------------------------------------------
// 管理画面：メディア → フォルダにアップロード
// ------------------------------------------------------------
add_action('admin_menu', function () {
	add_media_page('フォルダにアップロード', 'フォルダにアップロード', 'manage_options', 'dfu-upload', 'dfu_render_page');
});
// プラグイン一覧に、使う画面へのリンクを出す
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
	if (current_user_can('manage_options')) array_unshift($links, '<a href="' . esc_url(admin_url('upload.php?page=dfu-upload')) . '">フォルダにアップロード</a>');
	return $links;
});

/**
 * 見るだけ用：uploads の中の、すでにあるフォルダを返す（作らない）。リンクや外に出るものは WP_Error
 * 見るときは既存のフォルダ名（日本語など）も通す。ただし . .. と . で始まる名前、\ と NUL は不可
 */
function dfu_resolve_existing(string $base, string $rel) {
	$base_real = realpath($base);
	if ($base_real === false) return new WP_Error('dfu_base', 'uploads フォルダが見つかりません');
	$rel = trim(str_replace('\\', '/', $rel), '/');
	$cur = $base_real;
	foreach ($rel === '' ? [] : explode('/', $rel) as $seg) {
		if ($seg === '' || $seg[0] === '.' || strpos($seg, "\0") !== false) return new WP_Error('dfu_folder', 'フォルダ名が正しくありません');
		$next = $cur . '/' . $seg;
		if (is_link($next) || !is_dir($next)) return new WP_Error('dfu_nodir', 'フォルダが見つかりません: ' . $seg);
		$cur = realpath($next);
		if ($cur === false || ($cur !== $base_real && strpos($cur, $base_real . DIRECTORY_SEPARATOR) !== 0)) {
			return new WP_Error('dfu_escape', 'uploads の外は見られません');
		}
	}
	return ['dir' => $cur, 'rel' => $rel];
}

// フォルダの中身（サブフォルダとファイル）を返す
add_action('wp_ajax_dfu_list', function () {
	if (!current_user_can('manage_options')) dfu_fail('権限がありません', 403);
	if (!check_ajax_referer('dfu_upload', 'nonce', false)) dfu_fail('確認用の情報が古くなっています。ページを開き直してください', 403);
	$up = wp_get_upload_dir();
	$r = dfu_resolve_existing($up['basedir'], (string) wp_unslash($_POST['folder'] ?? ''));
	if (is_wp_error($r)) dfu_fail($r);
	$hide = ($_POST['hide'] ?? '1') === '1';
	$dirs = []; $files = []; $hidden = 0;
	$names = @scandir($r['dir']) ?: [];
	$set = array_flip($names);
	foreach ($names as $n) {
		if ($n === '.' || $n === '..' || $n[0] === '.') continue;
		$p = $r['dir'] . '/' . $n;
		if (is_link($p)) continue;
		if (is_dir($p)) {
			$has = false;
			foreach (@scandir($p) ?: [] as $c) { if ($c[0] !== '.' && is_dir("$p/$c") && !is_link("$p/$c")) { $has = true; break; } }
			$dirs[] = ['name' => $n, 'children' => $has];
			continue;
		}
		// 縮小版（-150x150 など）と、EWWW が作る WebP の複製（x.jpg.webp）は隠せる
		// 縮小版は元のファイルが同じフォルダにあるときだけ（ogp-1200x630.jpg のように名前に大きさが入った元画像は隠さない）
		if ($hide && (dfu_is_resized($n, $set) || (preg_match('/\.(jpe?g|png|gif)\.webp$/i', $n) && isset($set[substr($n, 0, -5)])))) { $hidden++; continue; }
		$files[] = ['name' => $n, 'size' => filesize($p), 'mtime' => filemtime($p)];
	}
	// 名前のフォルダを先に、年のフォルダ（2026 など）はあとに
	usort($dirs, fn($a, $b) => [ctype_digit($a['name']), $a['name']] <=> [ctype_digit($b['name']), $b['name']]);
	usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
	$total = count($files);
	$files = array_slice($files, 0, 400);
	$url = trailingslashit($up['baseurl']) . ($r['rel'] === '' ? '' : implode('/', array_map('rawurlencode', explode('/', $r['rel']))) . '/');
	foreach ($files as &$f) {
		$f['url'] = $url . rawurlencode($f['name']);
		// 画像なら縦横のピクセル（ヘッダーだけ読むので軽い）
		if (in_array(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)), dfu_image_exts(), true)) {
			$wh = @getimagesize($r['dir'] . '/' . $f['name']);
			if ($wh) { $f['w'] = $wh[0]; $f['h'] = $wh[1]; }
		}
	}
	unset($f);
	// メディアライブラリに登録されていれば、編集画面への URL を付ける
	if ($files) {
		global $wpdb;
		$pre = $r['rel'] === '' ? '' : $r['rel'] . '/';
		$rels = array_map(fn($f) => $pre . $f['name'], $files);
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN (" . implode(',', array_fill(0, count($rels), '%s')) . ')',
			$rels
		));
		$ids = [];
		foreach ($rows as $row) $ids[$row->meta_value] = (int) $row->post_id;
		foreach ($files as &$f) {
			$id = $ids[$pre . $f['name']] ?? 0;
			if ($id) { $f['id'] = $id; $f['edit'] = admin_url('post.php?post=' . $id . '&action=edit'); $f['alt'] = (string) get_post_meta($id, '_wp_attachment_image_alt', true); }
		}
		unset($f);
	}
	wp_send_json_success(['folder' => $r['rel'], 'dirs' => $dirs, 'files' => $files, 'total' => $total, 'hidden' => $hidden]);
});

function dfu_render_page() {
	if (!current_user_can('manage_options')) wp_die('権限がありません');
	$up = wp_get_upload_dir();
	$config = [
		'ajax'    => admin_url('admin-ajax.php'),
		'nonce'   => wp_create_nonce('dfu_upload'),
		'maxSize' => wp_max_upload_size(),
		'exts'    => array_keys(dfu_allowed_types()),
	];
	?>
	<style>
		.dfu-browser { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 12px; margin: 12px 0 20px; }
		.dfu-pane { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; min-height: 380px; max-height: 560px; overflow: auto; }
		.dfu-tree { padding: 6px 0; font-size: 13px; }
		.dfu-tree ul { margin: 0; padding-left: 16px; list-style: none; }
		.dfu-tree > ul { padding-left: 4px; }
		.dfu-tree li { margin: 0; }
		.dfu-row { display: flex; align-items: center; gap: 2px; padding: 2px 6px; border-radius: 4px; cursor: pointer; white-space: nowrap; }
		.dfu-row:hover { background: #f0f6fc; }
		.dfu-row.on { background: #2271b1; color: #fff; }
		.dfu-tog { width: 18px; text-align: center; color: #787c82; flex: none; user-select: none; }
		.dfu-row.on .dfu-tog { color: #fff; }
		.dfu-ico { flex: none; }
		.dfu-head { position: sticky; top: 0; z-index: 1; background: #f6f7f7; border-bottom: 1px solid #dcdcde; padding: 8px 10px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
		.dfu-crumb a { text-decoration: none; }
		.dfu-crumb .sep { color: #a7aaad; margin: 0 4px; }
		.dfu-head .dfu-sp { flex: 1; }
		.dfu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 10px; padding: 10px; }
		.dfu-card { border: 1px solid #dcdcde; border-radius: 6px; overflow: hidden; background: #fff; cursor: pointer; font-size: 11px; }
		.dfu-card:hover { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
		.dfu-thumb { height: 96px; display: grid; place-items: center; background: repeating-conic-gradient(#f0f0f1 0 25%, #fff 0 50%) 0 0 / 14px 14px; }
		.dfu-thumb img { max-width: 100%; max-height: 96px; object-fit: contain; }
		.dfu-thumb .dfu-ext { font-size: 18px; font-weight: 700; color: #50575e; }
		.dfu-meta { padding: 4px 6px; }
		.dfu-name { font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.dfu-size { color: #787c82; display: flex; justify-content: space-between; align-items: center; gap: 4px; }
		.dfu-ren { font: inherit; font-size: 10.5px; border: 1px solid #c3c4c7; background: #f6f7f7; border-radius: 3px; padding: 0 5px; cursor: pointer; color: #2271b1; }
		.dfu-ren:hover { border-color: #2271b1; }
		.dfu-views { display: inline-flex; }
		.dfu-views button { font-size: 15px; line-height: 1; padding: 3px 6px; border: 1px solid #c3c4c7; background: #fff; color: #787c82; cursor: pointer; }
		.dfu-views button + button { border-left: 0; }
		.dfu-views button.on { background: #2271b1; border-color: #2271b1; color: #fff; }
		.dfu-list { width: 100%; border-collapse: collapse; font-size: 13px; }
		.dfu-list th { text-align: left; font-weight: 600; padding: 6px 10px; border-bottom: 1px solid #dcdcde; color: #50575e; font-size: 12px; white-space: nowrap; }
		.dfu-list td { padding: 6px 10px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
		.dfu-list tr:hover td { background: #f6f7f7; }
		.dfu-list .dfu-lth { width: 60px; }
		.dfu-list .dfu-lth > div { width: 60px; height: 40px; display: grid; place-items: center; background: repeating-conic-gradient(#f0f0f1 0 25%, #fff 0 50%) 0 0 / 10px 10px; border-radius: 3px; overflow: hidden; }
		.dfu-list .dfu-lth img { max-width: 60px; max-height: 40px; object-fit: contain; }
		.dfu-list .dfu-lname { word-break: break-all; }
		.dfu-list .dfu-lname a { font-weight: 600; text-decoration: none; }
		.dfu-list .dfu-lname .dfu-unreg { color: #787c82; font-size: 11px; margin-left: 4px; }
		.dfu-list .dfu-lnum { white-space: nowrap; color: #50575e; }
		.dfu-list .dfu-lact { white-space: nowrap; text-align: right; }
		.dfu-list .dfu-lact button, .dfu-list .dfu-lact a { margin-left: 4px; }
		.dfu-edit { font-size: 10.5px; text-decoration: none; }
		.dfu-list .dfu-c-alt { min-width: 120px; color: #3c434a; word-break: break-all; }
		.dfu-list .dfu-none { color: #a7aaad; }
		.dfu-list.dfu-hide-thumb .dfu-c-thumb, .dfu-list.dfu-hide-alt .dfu-c-alt, .dfu-list.dfu-hide-px .dfu-c-px, .dfu-list.dfu-hide-size .dfu-c-size, .dfu-list.dfu-hide-date .dfu-c-date { display: none; }
		.dfu-cols { position: relative; }
		.dfu-cols[hidden] { display: none; }
		.dfu-cols summary { list-style: none; cursor: pointer; }
		.dfu-cols summary::-webkit-details-marker { display: none; }
		.dfu-cols .dfu-cols-menu { position: absolute; right: 0; top: calc(100% + 4px); z-index: 3; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: 0 3px 10px rgba(0, 0, 0, .12); padding: 6px 10px; white-space: nowrap; }
		.dfu-cols .dfu-cols-menu label { display: block; padding: 3px 0; }
		#dfu-view { position: relative; }
		.dfu-drop { display: none; position: absolute; inset: 0; z-index: 2; background: rgba(34, 113, 177, .12); border: 3px dashed #2271b1; border-radius: 6px; place-items: center; text-align: center; font-size: 15px; font-weight: 600; color: #135e96; pointer-events: none; }
		.dfu-over .dfu-drop { display: grid; }
		.dfu-sub { padding: 10px; display: flex; flex-wrap: wrap; gap: 6px; border-bottom: 1px dashed #dcdcde; }
		.dfu-sub button { display: inline-flex; gap: 4px; align-items: center; }
		.dfu-empty { padding: 30px; color: #787c82; text-align: center; }
		#dfu-toast { position: fixed; left: 50%; bottom: 30px; transform: translateX(-50%); background: #1d2327; color: #fff; padding: 8px 16px; border-radius: 20px; opacity: 0; transition: opacity .2s; pointer-events: none; z-index: 100000; }
		#dfu-toast.show { opacity: 1; }
		@media (max-width: 782px) { .dfu-browser { grid-template-columns: minmax(0, 1fr); } .dfu-pane { max-height: 360px; } }
	</style>
	<div class="wrap">
		<h1>フォルダにアップロード</h1>
		<p>左でフォルダを選ぶと、右に中のファイルが出て、そこが置き先になります。右の枠にファイルやフォルダをドラッグ＆ドロップすると、そのフォルダにすぐ置きます（下のオプションが使われます）。サムネイルをクリックすると URL をコピーします。一覧表示では、メディアに登録されているファイルの名前をクリックするとメディアの編集画面を開きます。</p>

		<div class="dfu-browser">
			<div class="dfu-pane dfu-tree" id="dfu-tree" aria-label="フォルダ"></div>
			<div class="dfu-pane" id="dfu-view">
				<div class="dfu-head">
					<span class="dfu-crumb" id="dfu-crumb"></span>
					<span class="dfu-sp"></span>
					<span class="dfu-views" role="group" aria-label="表示">
						<button type="button" data-v="grid" title="サムネイル表示" aria-label="サムネイル表示">▦</button><button type="button" data-v="list" title="一覧表示" aria-label="一覧表示">☰</button>
					</span>
					<details class="dfu-cols" id="dfu-cols" hidden>
						<summary class="button button-small">表示する欄 ▾</summary>
						<div class="dfu-cols-menu" id="dfu-cols-menu"></div>
					</details>
					<label><input type="checkbox" id="dfu-hide" checked> 縮小版・WebP の複製を隠す</label>
					<button type="button" class="button button-small" id="dfu-reload">再読み込み</button>
				</div>
				<div id="dfu-body"></div>
				<div class="dfu-drop"><div>ここに落とすと<br><span id="dfu-drop-to"></span><br>に置きます</div></div>
			</div>
		</div>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dfu-folder">置き先のフォルダ</label></th>
				<td>
					<input type="text" id="dfu-folder" class="regular-text" placeholder="空なら uploads の直下" autocomplete="off">
					<p class="description">左で選んだフォルダが入ります。書き足すと、その下に新しいフォルダを作って置きます（英数字・<code>-</code>・<code>_</code>・<code>.</code> と <code>/</code> だけ）。</p>
				</td>
			</tr>
			<tr>
				<th scope="row">ファイル</th>
				<td>
					<p><input type="file" id="dfu-files" multiple> <label><input type="checkbox" id="dfu-dirmode"> フォルダごと選ぶ（中のフォルダ構成もそのまま置きます）</label></p>
					<p class="description">置けるもの：<?php echo esc_html(implode(' / ', array_map(fn($e) => '.' . $e, array_keys(dfu_allowed_types())))); ?>（1ファイル <?php echo esc_html(size_format(wp_max_upload_size())); ?> まで）。中身も確かめ、種類が合わないものは置きません。</p>
				</td>
			</tr>
			<tr>
				<th scope="row">オプション</th>
				<td>
					<p><label><input type="checkbox" id="dfu-register" checked> メディアライブラリにも登録する（画像はサムネイルも作られます。ここで置いたファイルは WebP に変換せず、そのままの形で置きます）</label></p>
					<p>同じ名前のファイルがあるとき：
						<label><input type="radio" name="dfu-dup" value="rename" checked> 別の名前で置く</label>
						<label><input type="radio" name="dfu-dup" value="overwrite"> 上書きする</label>
					</p>
				</td>
			</tr>
		</table>
		<p><button type="button" class="button button-primary" id="dfu-start">アップロード</button> <span id="dfu-summary"></span></p>
		<ol id="dfu-log" style="max-height:300px;overflow:auto;background:#fff;border:1px solid #dcdcde;padding:8px 8px 8px 32px"></ol>
		<div id="dfu-toast" role="status" aria-live="polite"></div>
	</div>
	<script>
	(() => {
		const C = <?php echo wp_json_encode($config); ?>;
		const $ = id => document.getElementById(id);
		const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
		const IMG = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'svg'];
		const size = n => n < 1024 ? n + ' B' : n < 1048576 ? (n / 1024).toFixed(0) + ' KB' : (n / 1048576).toFixed(1) + ' MB';
		let toastT;
		const toast = m => { const t = $('dfu-toast'); t.textContent = m; t.classList.add('show'); clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('show'), 2200); };
		const post = async (action, data) => {
			const fd = new FormData();
			fd.append('action', action); fd.append('nonce', C.nonce);
			for (const [k, v] of Object.entries(data)) fd.append(k, v);
			const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
			return r.json();
		};

		// ---- 左：フォルダの木（開いたときに中を読む）
		let current = '';
		function treeRow(name, path, children) {
			const li = document.createElement('li');
			li.dataset.path = path;
			li.innerHTML = `<div class="dfu-row"><span class="dfu-tog">${children ? '▸' : ''}</span><span class="dfu-ico">📁</span> <span>${esc(name)}</span></div>`;
			const row = li.firstChild, tog = row.firstChild;
			// 名前を押すと開いて中を出す。▸ / ▾ を押すと開け閉めだけ
			row.onclick = async e => {
				if (children) await toggle(li, tog, e.target !== tog);
				if (e.target !== tog) open(path);
			};
			return li;
		}
		async function toggle(li, tog, forceOpen) {
			let ul = li.querySelector(':scope > ul');
			if (ul && !forceOpen) { ul.remove(); tog.textContent = '▸'; return; }
			if (ul) return;
			const j = await post('dfu_list', { folder: li.dataset.path, hide: '1' });
			if (!j.success) return;
			ul = document.createElement('ul');
			for (const d of j.data.dirs) ul.appendChild(treeRow(d.name, (li.dataset.path ? li.dataset.path + '/' : '') + d.name, d.children));
			li.appendChild(ul);
			tog.textContent = '▾';
		}
		function markOn() {
			document.querySelectorAll('.dfu-row.on').forEach(r => r.classList.remove('on'));
			const li = [...document.querySelectorAll('#dfu-tree li')].find(l => l.dataset.path === current);
			if (li) li.firstChild.classList.add('on');
		}

		// ---- 右：中身（サブフォルダとサムネイル）
		async function open(path) {
			current = path;
			$('dfu-folder').value = path;
			markOn();
			const parts = path ? path.split('/') : [];
			$('dfu-crumb').innerHTML = [`<a href="#" data-p="">uploads</a>`, ...parts.map((p, i) => `<span class="sep">/</span><a href="#" data-p="${esc(parts.slice(0, i + 1).join('/'))}">${esc(p)}</a>`)].join('');
			$('dfu-crumb').querySelectorAll('a').forEach(a => a.onclick = e => { e.preventDefault(); open(a.dataset.p); });
			$('dfu-body').innerHTML = '<div class="dfu-empty">読み込み中…</div>';
			const j = await post('dfu_list', { folder: path, hide: $('dfu-hide').checked ? '1' : '0' });
			if (!j.success) { $('dfu-body').innerHTML = `<div class="dfu-empty">${esc(j.data && j.data.message || '読み込めませんでした')}</div>`; return; }
			const d = j.data;
			let h = '';
			if (d.dirs.length) h += '<div class="dfu-sub">' + d.dirs.map(x => `<button type="button" class="button button-small" data-d="${esc(x.name)}">📁 ${esc(x.name)}</button>`).join('') + '</div>';
			const thumb = f => {
				const ext = f.name.split('.').pop().toLowerCase();
				return IMG.includes(ext) ? `<img src="${esc(f.url)}" alt="" loading="lazy">` : `<span class="dfu-ext">.${esc(ext)}</span>`;
			};
			if (d.files.length && view === 'list') {
				h += `<table class="dfu-list ${colClass()}"><thead><tr><th class="dfu-c-thumb"></th><th>ファイル名</th><th class="dfu-c-alt">代替テキスト</th><th class="dfu-c-px">ピクセル</th><th class="dfu-c-size">大きさ</th><th class="dfu-c-date">更新日</th><th></th></tr></thead><tbody>` + d.files.map(f => {
					const nm = f.edit ? `<a href="${esc(f.edit)}" title="メディアの編集画面を開く">${esc(f.name)}</a>` : `${esc(f.name)}<span class="dfu-unreg">（メディア未登録）</span>`;
					const alt = !f.edit ? '<span class="dfu-none">—</span>' : f.alt ? esc(f.alt) : '<span class="dfu-none">（なし）</span>';
					const px = f.w ? `${f.w}×${f.h}` : '<span class="dfu-none">—</span>';
					return `<tr data-url="${esc(f.url)}" data-name="${esc(f.name)}"><td class="dfu-lth dfu-c-thumb"><div>${thumb(f)}</div></td><td class="dfu-lname">${nm}</td><td class="dfu-c-alt">${alt}</td><td class="dfu-lnum dfu-c-px">${px}</td><td class="dfu-lnum dfu-c-size">${size(f.size)}</td><td class="dfu-lnum dfu-c-date">${ymd(f.mtime)}</td><td class="dfu-lact"><button type="button" class="button button-small dfu-copy">URL をコピー</button><button type="button" class="button button-small dfu-ren2">✎ 名前</button></td></tr>`;
				}).join('') + '</tbody></table>';
			} else if (d.files.length) {
				h += '<div class="dfu-grid">' + d.files.map(f => {
					const ed = f.edit ? ` <a class="dfu-edit" href="${esc(f.edit)}" title="メディアの編集画面を開く">編集</a>` : '';
					return `<div class="dfu-card" data-url="${esc(f.url)}" data-name="${esc(f.name)}" title="${esc(f.name)}（クリックで URL をコピー）"><div class="dfu-thumb">${thumb(f)}</div><div class="dfu-meta"><div class="dfu-name">${esc(f.name)}</div><div class="dfu-size">${size(f.size)}${ed} <button type="button" class="dfu-ren" title="ファイル名を変える">✎ 名前</button></div></div></div>`;
				}).join('') + '</div>';
			}
			if (!d.dirs.length && !d.files.length) h = '<div class="dfu-empty">空のフォルダです</div>';
			if (d.total > d.files.length) h += `<div class="dfu-empty">ほか ${d.total - d.files.length} 件（多いので最初の ${d.files.length} 件だけ表示）</div>`;
			if (d.hidden) h += `<div class="dfu-empty" style="padding:8px">縮小版・WebP の複製 ${d.hidden} 件を隠しています</div>`;
			$('dfu-body').innerHTML = h;
			$('dfu-body').querySelectorAll('[data-d]').forEach(b => b.onclick = async () => {
				const next = (path ? path + '/' : '') + b.dataset.d;
				const li = [...document.querySelectorAll('#dfu-tree li')].find(l => l.dataset.path === path);
				if (li) await toggle(li, li.firstChild.firstChild, true);
				open(next);
			});
			$('dfu-body').querySelectorAll('.dfu-card').forEach(c => c.onclick = async e => {
				if (e.target.closest('.dfu-edit')) return;
				if (e.target.closest('.dfu-ren')) return rename(c.dataset.name);
				copy(c.dataset.url);
			});
			$('dfu-body').querySelectorAll('.dfu-list tbody tr').forEach(tr => {
				tr.querySelector('.dfu-copy').onclick = () => copy(tr.dataset.url);
				tr.querySelector('.dfu-ren2').onclick = () => rename(tr.dataset.name);
			});
		}
		async function copy(url) {
			try { await navigator.clipboard.writeText(url); toast('URL をコピーしました'); } catch (err) { prompt('URL', url); }
		}
		const ymd = t => { const d = new Date(t * 1000); return `${d.getFullYear()}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getDate()).padStart(2, '0')}`; };
		// サムネイル表示 / 一覧表示（選んだほうをこのブラウザで覚える）
		let view = 'grid';
		try { if (localStorage.getItem('dfu-view') === 'list') view = 'list'; } catch (e) {}
		const markView = () => {
			document.querySelectorAll('.dfu-views button').forEach(b => b.classList.toggle('on', b.dataset.v === view));
			$('dfu-cols').hidden = view !== 'list';
		};
		// 一覧表示で出す欄（隠した欄をこのブラウザで覚える）
		const COLS = [['thumb', 'サムネイル'], ['alt', '代替テキスト'], ['px', 'ピクセル'], ['size', '大きさ'], ['date', '更新日']];
		let hiddenCols = [];
		try { hiddenCols = JSON.parse(localStorage.getItem('dfu-hidden-cols') || '[]'); } catch (e) {}
		const colClass = () => hiddenCols.map(c => 'dfu-hide-' + c).join(' ');
		$('dfu-cols-menu').innerHTML = COLS.map(([k, l]) => `<label><input type="checkbox" value="${k}"${hiddenCols.includes(k) ? '' : ' checked'}> ${l}</label>`).join('');
		$('dfu-cols-menu').querySelectorAll('input').forEach(i => i.onchange = () => {
			hiddenCols = [...$('dfu-cols-menu').querySelectorAll('input:not(:checked)')].map(x => x.value);
			try { localStorage.setItem('dfu-hidden-cols', JSON.stringify(hiddenCols)); } catch (e) {}
			const t = document.querySelector('.dfu-list');
			if (t) t.className = 'dfu-list ' + colClass();
		});
		document.addEventListener('click', e => { if (!e.target.closest('#dfu-cols')) $('dfu-cols').open = false; });
		document.querySelectorAll('.dfu-views button').forEach(b => b.onclick = () => {
			view = b.dataset.v; markView();
			try { localStorage.setItem('dfu-view', view); } catch (e) {}
			open(current);
		});
		markView();
		// ファイル名を変える（メディアに登録されていれば、サイズ違いと記事の本文の URL もまとめて変わる）
		async function rename(name) {
			const base = name.replace(/\.[^.]+$/, '');
			const next = prompt(`新しいファイル名（拡張子なし・英数字と - _ だけ）\n今: ${name}`, base);
			if (next === null || next.trim() === '' || next.trim() === base) return;
			const j = await post('dfu_rename', { folder: current, name, new: next.trim() });
			if (!j.success) { alert(j.data && j.data.message || '名前を変えられませんでした'); return; }
			const d = j.data;
			let msg = `${d.from} → ${d.to}\nファイル ${d.files} 個の名前を変えました。記事の本文の URL を書き換えた記事：${d.posts} 件`;
			if (d.others && d.others.length) msg += `\n\n本文以外に古い名前が残っている場所（自動では変えていません）：\n・` + d.others.join('\n・');
			alert(msg);
			open(current);
		}
		$('dfu-hide').onchange = () => open(current);
		$('dfu-reload').onclick = () => open(current);

		// 最初：uploads を開いた状態
		const root = document.createElement('ul');
		const rootLi = treeRow('uploads', '', true);
		root.appendChild(rootLi);
		$('dfu-tree').appendChild(root);
		toggle(rootLi, rootLi.firstChild.firstChild, true).then(() => open(''));

		// ---- アップロード
		$('dfu-dirmode').onchange = e => { $('dfu-files').toggleAttribute('webkitdirectory', e.target.checked); $('dfu-files').value = ''; };
		$('dfu-start').onclick = () => {
			const files = [...$('dfu-files').files];
			if (!files.length) { alert('ファイルを選んでください'); return; }
			upload(files.map(f => ({ file: f, rel: f.webkitRelativePath || '' })));
		};
		// items: [{ file, rel }]。rel はフォルダごと置くときの「フォルダ/…/名前」（なければ空）
		let busy = false;
		async function upload(items) {
			if (busy) { toast('アップロード中です。終わってからもう一度'); return; }
			busy = true;
			const files = items;
			const btn = $('dfu-start'); btn.disabled = true;
			const log = $('dfu-log'); log.innerHTML = '';
			let ok = 0, ng = 0;
			for (const { file: f, rel: relpath } of files) {
				const li = document.createElement('li');
				const rel = relpath || f.name;
				li.textContent = rel + ' … ';
				log.appendChild(li);
				const ext = f.name.split('.').pop().toLowerCase();
				if (!C.exts.includes(ext)) { li.innerHTML = esc(rel) + ' … <b style="color:#b32d2e">置けない種類</b>'; ng++; continue; }
				if (f.size > C.maxSize) { li.innerHTML = esc(rel) + ' … <b style="color:#b32d2e">大きすぎます</b>'; ng++; continue; }
				try {
					const j = await post('dfu_upload', {
						folder: $('dfu-folder').value, relpath,
						register: $('dfu-register').checked ? '1' : '0',
						dup: document.querySelector('input[name="dfu-dup"]:checked').value, file: f,
					});
					if (j.success) {
						ok++;
						li.innerHTML = esc(rel) + ' … <a href="' + esc(j.data.url) + '" target="_blank" rel="noopener">' + esc(j.data.url) + '</a>'
							+ (j.data.renamed ? '（名前を変えて置きました）' : '') + (j.data.attachment ? '（メディア #' + j.data.attachment + '）' : '');
					} else {
						ng++;
						li.innerHTML = esc(rel) + ' … <b style="color:#b32d2e">' + esc(j.data && j.data.message || '失敗しました') + '</b>';
					}
				} catch (e) {
					ng++;
					li.innerHTML = esc(rel) + ' … <b style="color:#b32d2e">通信に失敗しました（ログインし直してからもう一度）</b>';
				}
				$('dfu-summary').textContent = `成功 ${ok} ／ 失敗 ${ng} ／ 全部 ${files.length}`;
			}
			btn.disabled = false; busy = false;
			// 置いたフォルダを開き直す（新しいフォルダができていれば木にも出す）
			const target = $('dfu-folder').value.replace(/^\/+|\/+$/g, '');
			const parentLi = [...document.querySelectorAll('#dfu-tree li')].find(l => l.dataset.path === target.split('/').slice(0, -1).join('/'));
			if (parentLi && parentLi.querySelector(':scope > ul')) { parentLi.querySelector(':scope > ul').remove(); await toggle(parentLi, parentLi.firstChild.firstChild, true); }
			open(target);
		}

		// ---- ドラッグ＆ドロップ：右の枠に落とすと、いま開いているフォルダに置く（フォルダを落とすと中の構成ごと）
		async function readEntry(entry, out) {
			if (entry.isFile) {
				const file = await new Promise((ok, ng) => entry.file(ok, ng));
				out.push({ file, rel: entry.fullPath.includes('/', 1) ? entry.fullPath.replace(/^\//, '') : '' });
			} else if (entry.isDirectory) {
				const reader = entry.createReader();
				for (;;) {
					const batch = await new Promise((ok, ng) => reader.readEntries(ok, ng));
					if (!batch.length) break;
					for (const e of batch) await readEntry(e, out);
				}
			}
		}
		const zone = $('dfu-view');
		let depth = 0;
		const isFiles = e => [...(e.dataTransfer && e.dataTransfer.types || [])].includes('Files');
		window.addEventListener('dragover', e => { if (isFiles(e)) e.preventDefault(); });
		window.addEventListener('drop', e => { if (isFiles(e)) e.preventDefault(); });
		zone.addEventListener('dragenter', e => {
			if (!isFiles(e)) return;
			depth++;
			$('dfu-drop-to').textContent = 'uploads' + (current ? '/' + current : '');
			zone.classList.add('dfu-over');
		});
		zone.addEventListener('dragleave', () => { if (--depth <= 0) { depth = 0; zone.classList.remove('dfu-over'); } });
		zone.addEventListener('dragover', e => { if (isFiles(e)) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; } });
		zone.addEventListener('drop', async e => {
			if (!isFiles(e)) return;
			e.preventDefault();
			depth = 0; zone.classList.remove('dfu-over');
			const entries = [...e.dataTransfer.items].map(i => i.webkitGetAsEntry && i.webkitGetAsEntry()).filter(Boolean);
			const items = [];
			if (entries.length) { for (const en of entries) await readEntry(en, items); }
			else for (const f of e.dataTransfer.files) items.push({ file: f, rel: '' });
			if (!items.length) return;
			$('dfu-folder').value = current;
			upload(items);
		});
	})();
	</script>
	<?php
}

// ------------------------------------------------------------
// 1ファイルずつ受け取る
// ------------------------------------------------------------
add_action('wp_ajax_dfu_upload', 'dfu_handle_upload');

function dfu_fail($msg, $code = 400) {
	wp_send_json_error(['message' => $msg instanceof WP_Error ? $msg->get_error_message() : $msg], $code);
}

function dfu_handle_upload() {
	if (!current_user_can('manage_options')) dfu_fail('権限がありません', 403);
	if (!check_ajax_referer('dfu_upload', 'nonce', false)) dfu_fail('確認用の情報が古くなっています。ページを開き直してください', 403);
	if (empty($_FILES['file']) || !is_array($_FILES['file'])) dfu_fail('ファイルが届いていません');
	$file = $_FILES['file'];
	if ((int) $file['error'] !== UPLOAD_ERR_OK) dfu_fail('アップロードに失敗しました（エラー ' . (int) $file['error'] . '）');
	if (!is_uploaded_file($file['tmp_name'])) dfu_fail('ファイルが正しく届いていません');
	if ($file['size'] > wp_max_upload_size()) dfu_fail('ファイルが大きすぎます');

	// 置き先：指定のフォルダ ＋（フォルダごとのとき）中のフォルダ構成
	$folder = dfu_normalize_folder(wp_unslash($_POST['folder'] ?? ''));
	if (is_wp_error($folder)) dfu_fail($folder);
	$relpath = str_replace('\\', '/', (string) wp_unslash($_POST['relpath'] ?? ''));
	$sub = '';
	if ($relpath !== '' && strpos($relpath, '/') !== false) {
		$sub = dfu_normalize_folder(dirname($relpath));
		if (is_wp_error($sub)) dfu_fail($sub);
	}
	$rel_dir = trim($folder . '/' . $sub, '/');
	if ($rel_dir !== '' && is_wp_error($chk = dfu_normalize_folder($rel_dir))) dfu_fail($chk);

	$named = dfu_check_name($file['name']);
	if (is_wp_error($named)) dfu_fail($named);
	[$name, $ext] = $named;
	$mime = dfu_check_content($file['tmp_name'], $ext);
	if (is_wp_error($mime)) dfu_fail($mime);

	$up = wp_get_upload_dir();
	$dir = dfu_prepare_dir($up['basedir'], $rel_dir);
	if (is_wp_error($dir)) dfu_fail($dir);

	$renamed = false;
	$dest = $dir . '/' . $name;
	if (file_exists($dest)) {
		if (($_POST['dup'] ?? 'rename') === 'overwrite') {
			if (!is_file($dest) || is_link($dest)) dfu_fail('上書きできないファイルです');
		} else {
			$name = wp_unique_filename($dir, $name);
			$dest = $dir . '/' . $name;
			$renamed = true;
		}
	}
	if (!@move_uploaded_file($file['tmp_name'], $dest)) dfu_fail('ファイルを置けませんでした', 500);
	@chmod($dest, 0644);

	$rel_file = ($rel_dir === '' ? '' : $rel_dir . '/') . $name;
	$url = trailingslashit($up['baseurl']) . $rel_file;

	// メディアライブラリに登録（同じ場所のものが登録済みなら、情報を作り直す）
	$att_id = 0;
	if (($_POST['register'] ?? '0') === '1') {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$found = get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1,
			'meta_key' => '_wp_attached_file', 'meta_value' => $rel_file]);
		if ($found) {
			$att_id = (int) $found[0];
		} else {
			$att_id = wp_insert_attachment([
				'guid'           => $url,
				'post_mime_type' => $mime === 'text/plain' ? 'text/css' : $mime,
				'post_title'     => sanitize_text_field(pathinfo($name, PATHINFO_FILENAME)),
				'post_status'    => 'inherit',
			], $dest, 0, true);
			if (is_wp_error($att_id)) dfu_fail('置きましたが、メディアへの登録に失敗しました: ' . $att_id->get_error_message(), 500);
		}
		wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $dest));
	}

	wp_send_json_success(['url' => $url, 'path' => $rel_file, 'renamed' => $renamed, 'attachment' => $att_id]);
}


// ------------------------------------------------------------
// ファイル名を変える（メディアの添付ファイル・ただのファイル）
//   添付ファイルは、元の画像・-scaled・サイズ違いを全部まとめて変え、メディアの記録と
//   記事の本文の URL も書き換える。本文以外（設定など）に古い URL があれば、場所を知らせるだけ
// ------------------------------------------------------------

/** 新しい名前（拡張子なし）：英数字・-・_ だけ */
function dfu_check_new_base(string $base) {
	$base = trim($base);
	if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $base)) return new WP_Error('dfu_rename', '新しい名前は英数字・-・_ だけにしてください（拡張子は付けない）');
	return $base;
}

/** 変更の記録（最新 200 件）。前の名前に戻すときに使う */
function dfu_rename_log(array $row): void {
	$log = get_option('dfu_rename_log', []);
	if (!is_array($log)) $log = [];
	array_unshift($log, $row + ['time' => current_time('mysql')]);
	update_option('dfu_rename_log', array_slice($log, 0, 200), false);
}

/** 本文の URL を置き換える（生の名前と URL エンコードした名前の両方）。書き換えた記事の数を返す */
function dfu_replace_in_posts(string $dir_url, array $map, string $needle): int {
	global $wpdb;
	$pairs = [];
	foreach ($map as $old => $new) {
		$pairs[$dir_url . '/' . $old] = $dir_url . '/' . $new;
		$enc = rawurlencode($old);
		if ($enc !== $old) $pairs[$dir_url . '/' . $enc] = $dir_url . '/' . $new;
	}
	// 長いものから置き換える（photo.webp が photo-150x150.webp の途中を置き換えないように）
	uksort($pairs, fn($a, $b) => strlen($b) <=> strlen($a));
	$likes = array_unique([$needle, rawurlencode($needle)]);
	$where = implode(' OR ', array_fill(0, count($likes), 'post_content LIKE %s'));
	$ids = $wpdb->get_col($wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'revision' AND ($where)",
		array_map(fn($l) => '%' . $wpdb->esc_like($l) . '%', $likes)
	));
	$changed = 0;
	foreach ($ids as $id) {
		$c = get_post_field('post_content', $id, 'raw');
		$n = strtr($c, $pairs);
		if ($n !== $c) {
			$wpdb->update($wpdb->posts, ['post_content' => $n], ['ID' => $id]);
			clean_post_cache($id);
			$changed++;
		}
	}
	return $changed;
}

/** 本文以外（post meta・設定）に古い名前が残っている場所を数える（書き換えはしない） */
function dfu_find_other_refs(array $old_names): array {
	global $wpdb;
	$out = [];
	foreach ($old_names as $old) {
		foreach (array_unique([$old, rawurlencode($old)]) as $n) {
			$like = '%' . $wpdb->esc_like('/' . $n) . '%';
			foreach ($wpdb->get_results($wpdb->prepare("SELECT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key NOT IN ('_wp_attached_file','_wp_attachment_metadata') AND meta_value LIKE %s LIMIT 20", $like)) as $r) {
				$out["投稿 #{$r->post_id} の {$r->meta_key}"] = true;
			}
			foreach ($wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name <> 'dfu_rename_log' AND option_value LIKE %s LIMIT 20", $like)) as $o) {
				$out["設定 {$o}"] = true;
			}
		}
	}
	return array_keys($out);
}

/**
 * 添付ファイルの名前を変える。返り値は結果の配列か WP_Error
 */
function dfu_rename_attachment(int $id, string $new_base) {
	$new_base = dfu_check_new_base($new_base);
	if (is_wp_error($new_base)) return $new_base;
	$main = get_attached_file($id, true);
	$meta = wp_get_attachment_metadata($id);
	if (!$main || !is_file($main)) return new WP_Error('dfu_rename', 'ファイルが見つかりません');
	$dir = dirname($main);
	$up = wp_get_upload_dir();
	if (strpos(realpath($dir) . '/', realpath($up['basedir']) . '/') !== 0) return new WP_Error('dfu_rename', 'uploads の外のファイルは変えられません');
	$ext = pathinfo($main, PATHINFO_EXTENSION);
	// 名前の元（-scaled などが付く前の名前）
	$orig_name = !empty($meta['original_image']) ? $meta['original_image'] : wp_basename($main);
	$old_base = pathinfo($orig_name, PATHINFO_FILENAME);
	if ($old_base === $new_base) return new WP_Error('dfu_rename', '今と同じ名前です');

	// 変える対象：メイン・元の画像・サイズ違い（どれも古い名前で始まるものだけ）
	$names = [wp_basename($main)];
	if (!empty($meta['original_image'])) $names[] = $meta['original_image'];
	foreach (($meta['sizes'] ?? []) as $s) if (!empty($s['file'])) $names[] = $s['file'];
	$names = array_values(array_unique($names));
	$map = [];
	foreach ($names as $n) {
		if (strpos($n, $old_base) !== 0) return new WP_Error('dfu_rename', 'サイズ違いの名前が揃っていないため、変えられません: ' . $n);
		$map[$n] = $new_base . substr($n, strlen($old_base));
	}
	foreach ($map as $old => $new) {
		if (file_exists("$dir/$new")) return new WP_Error('dfu_rename', '同じ名前のファイルがもうあります: ' . $new);
	}
	// ファイルの名前を変える（途中で失敗したら、変えた分を戻す）
	$done = [];
	foreach ($map as $old => $new) {
		if (!is_file("$dir/$old")) continue;   // サイズ違いが無いのは気にしない
		if (!@rename("$dir/$old", "$dir/$new")) {
			foreach (array_reverse($done) as [$o, $n]) @rename("$dir/$n", "$dir/$o");
			return new WP_Error('dfu_rename', 'ファイルの名前を変えられませんでした: ' . $old);
		}
		$done[] = [$old, $new];
	}

	// メディアの記録
	$rel_dir = ltrim(str_replace(realpath($up['basedir']), '', realpath($dir)), '/');
	$new_main = $map[wp_basename($main)];
	update_post_meta($id, '_wp_attached_file', ($rel_dir === '' ? '' : $rel_dir . '/') . $new_main);
	if (is_array($meta)) {
		if (!empty($meta['file'])) $meta['file'] = ($rel_dir === '' ? '' : $rel_dir . '/') . $new_main;
		if (!empty($meta['original_image'])) $meta['original_image'] = $map[$meta['original_image']];
		foreach (($meta['sizes'] ?? []) as $k => $s) if (!empty($s['file']) && isset($map[$s['file']])) $meta['sizes'][$k]['file'] = $map[$s['file']];
		wp_update_attachment_metadata($id, $meta);
	}
	global $wpdb;
	$dir_url = $up['baseurl'] . ($rel_dir === '' ? '' : '/' . $rel_dir);
	$wpdb->update($wpdb->posts, ['guid' => $dir_url . '/' . $new_main], ['ID' => $id]);
	clean_post_cache($id);

	// 記事の本文の URL
	$posts = dfu_replace_in_posts($dir_url, $map, $old_base);
	$others = dfu_find_other_refs(array_keys($map));
	dfu_rename_log(['type' => 'attachment', 'id' => $id, 'dir' => $rel_dir, 'from' => $old_base, 'to' => $new_base, 'files' => $map]);
	return ['url' => $dir_url . '/' . $new_main, 'files' => count($done), 'posts' => $posts, 'others' => $others, 'from' => $old_base, 'to' => $new_base];
}

/** メディアに登録していない、ただのファイルの名前を変える（フォルダの画面から） */
function dfu_rename_plain_file(string $rel_dir, string $name, string $new_base) {
	$new_base = dfu_check_new_base($new_base);
	if (is_wp_error($new_base)) return $new_base;
	$up = wp_get_upload_dir();
	$r = dfu_resolve_existing($up['basedir'], $rel_dir);
	if (is_wp_error($r)) return $r;
	$name = wp_basename($name);
	$old = $r['dir'] . '/' . $name;
	if ($name === '' || $name[0] === '.' || !is_file($old) || is_link($old)) return new WP_Error('dfu_rename', 'ファイルが見つかりません');
	$ext = pathinfo($name, PATHINFO_EXTENSION);
	$new_name = $new_base . ($ext !== '' ? '.' . $ext : '');
	if ($new_name === $name) return new WP_Error('dfu_rename', '今と同じ名前です');
	if (file_exists($r['dir'] . '/' . $new_name)) return new WP_Error('dfu_rename', '同じ名前のファイルがもうあります: ' . $new_name);
	// メディアのサイズ違いなど、記録に出てくるファイルは単独で変えさせない
	global $wpdb;
	$in_meta = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attachment_metadata' AND meta_value LIKE %s LIMIT 1", '%' . $wpdb->esc_like('"' . $name . '"') . '%'));
	if ($in_meta) return new WP_Error('dfu_rename', 'これはメディア #' . $in_meta . ' の一部（サイズ違い）です。元の画像の名前を変えてください');
	if (!@rename($old, $r['dir'] . '/' . $new_name)) return new WP_Error('dfu_rename', 'ファイルの名前を変えられませんでした');
	$dir_url = $up['baseurl'] . ($r['rel'] === '' ? '' : '/' . $r['rel']);
	$posts = dfu_replace_in_posts($dir_url, [$name => $new_name], pathinfo($name, PATHINFO_FILENAME));
	$others = dfu_find_other_refs([$name]);
	dfu_rename_log(['type' => 'file', 'dir' => $r['rel'], 'from' => $name, 'to' => $new_name]);
	return ['url' => $dir_url . '/' . $new_name, 'files' => 1, 'posts' => $posts, 'others' => $others, 'from' => $name, 'to' => $new_name];
}

// フォルダの画面から：そのファイルがメディアに登録されていればメディアとして、なければただのファイルとして変える
add_action('wp_ajax_dfu_rename', function () {
	if (!current_user_can('manage_options')) dfu_fail('権限がありません', 403);
	if (!check_ajax_referer('dfu_upload', 'nonce', false)) dfu_fail('確認用の情報が古くなっています。ページを開き直してください', 403);
	$rel_dir = trim(str_replace('\\', '/', (string) wp_unslash($_POST['folder'] ?? '')), '/');
	$name = wp_basename((string) wp_unslash($_POST['name'] ?? ''));
	$new = (string) wp_unslash($_POST['new'] ?? '');
	global $wpdb;
	$rel_file = ($rel_dir === '' ? '' : $rel_dir . '/') . $name;
	$id = (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1", $rel_file));
	$r = $id ? dfu_rename_attachment($id, $new) : dfu_rename_plain_file($rel_dir, $name, $new);
	if (is_wp_error($r)) dfu_fail($r);
	wp_send_json_success($r + ['attachment' => $id]);
});

// メディアライブラリ：添付ファイルの詳細に「ファイル名」の欄を足す（管理者だけ）
add_filter('attachment_fields_to_edit', function ($fields, $post) {
	if (!current_user_can('manage_options')) return $fields;
	$meta = wp_get_attachment_metadata($post->ID);
	$file = get_attached_file($post->ID, true);
	if (!$file) return $fields;
	$base = pathinfo(!empty($meta['original_image']) ? $meta['original_image'] : wp_basename($file), PATHINFO_FILENAME);
	$fields['dfu_rename'] = [
		'label' => 'ファイル名',
		'input' => 'html',
		'html'  => '<input type="text" class="text" name="attachments[' . $post->ID . '][dfu_rename]" value="' . esc_attr($base) . '" style="width:100%">',   // 今の名前が日本語でも他の欄を保存できるよう、ブラウザ側の形式チェックは付けない（サーバーで確かめる）
		'helps' => '拡張子は付けずに、英数字・-・_ で。変えると、サイズ違いのファイルと、記事の本文の URL もまとめて書き換えます。',
	];
	return $fields;
}, 10, 2);

add_filter('attachment_fields_to_save', function ($post, $attachment) {
	if (!isset($attachment['dfu_rename']) || !current_user_can('manage_options')) return $post;
	$meta = wp_get_attachment_metadata($post['ID']);
	$file = get_attached_file($post['ID'], true);
	$base = pathinfo(!empty($meta['original_image']) ? $meta['original_image'] : wp_basename((string) $file), PATHINFO_FILENAME);
	$new = trim((string) $attachment['dfu_rename']);
	if ($new === '' || $new === $base) return $post;
	$r = dfu_rename_attachment((int) $post['ID'], $new);
	if (is_wp_error($r)) {
		$post['errors']['dfu_rename']['errors'][] = $r->get_error_message();
	} else {
		$post['guid'] = $r['url'];   // このあと WordPress が $post で保存するので、古い URL に戻らないように
	}
	return $post;
}, 10, 2);
