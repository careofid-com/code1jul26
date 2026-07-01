<?php

/* filename: /include/phpqrcode/svg.php */
require_once __DIR__.'/phpqrcode.php';

function qr_svg_with_window($text, $winX, $winY, $winW, $winH, $size=8, $margin=2){
    $qr = QRcode::svg($text, false, QR_ECLEVEL_H, $size, $margin);
    $svg = simplexml_load_string($qr);

    foreach ($svg->rect as $r){
        $x=(int)$r['x']; $y=(int)$r['y']; $w=(int)$r['width']; $h=(int)$r['height'];
        if ($x>$winX && $x<($winX+$winW) && $y>$winY && $y<($winY+$winH)){
            unset($r[0]);
        }
    }
    return $svg->asXML();
}