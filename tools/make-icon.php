<?php
/* Fabrique l'icône du plugin.
 *
 *   php tools/make-icon.php
 *
 * Dessinée ici plutôt que déposée en binaire opaque : on peut la relire et la
 * refaire. Le dessin est fait en 1024 puis réduit en 256, ce qui donne les
 * bords lissés que GD ne produit pas sur un remplissage direct.
 *
 * Un disque coupé en deux — orange pour la chaudière, bleu pour le froid — et
 * un thermomètre au milieu : le plugin tient en cette image, un seul
 * thermostat qui choisit entre chaud et froid.
 */

const TAILLE = 256;
const ECHELLE = 4;

$grand = imagecreatetruecolor(TAILLE * ECHELLE, TAILLE * ECHELLE);
imagesavealpha($grand, true);
imagealphablending($grand, false);
imagefill($grand, 0, 0, imagecolorallocatealpha($grand, 0, 0, 0, 127));
imagealphablending($grand, true);

$orange = imagecolorallocate($grand, 0xF2, 0x7A, 0x1A);
$bleu   = imagecolorallocate($grand, 0x2B, 0x8C, 0xD8);
$blanc  = imagecolorallocate($grand, 0xFF, 0xFF, 0xFF);
$rouge  = imagecolorallocate($grand, 0xD6, 0x33, 0x2B);
$gris   = imagecolorallocate($grand, 0x36, 0x44, 0x4B);

$e = function ($_valeur) { return (int) round($_valeur * ECHELLE); };

/* Le disque : la moitié gauche chauffe, la droite refroidit. */
imagefilledarc($grand, $e(128), $e(128), $e(236), $e(236), 90, 270, $orange, IMG_ARC_PIE);
imagefilledarc($grand, $e(128), $e(128), $e(236), $e(236), 270, 90, $bleu, IMG_ARC_PIE);

/* Le thermomètre, en blanc cerclé de gris : le tube, puis le bulbe. */
imagefilledrectangle($grand, $e(110), $e(46), $e(146), $e(170), $gris);
imagefilledellipse($grand, $e(128), $e(46), $e(36), $e(36), $gris);
imagefilledellipse($grand, $e(128), $e(184), $e(66), $e(66), $gris);
imagefilledrectangle($grand, $e(118), $e(46), $e(138), $e(170), $blanc);
imagefilledellipse($grand, $e(128), $e(46), $e(20), $e(20), $blanc);
imagefilledellipse($grand, $e(128), $e(184), $e(50), $e(50), $blanc);

/* La colonne de mercure, à mi-hauteur. */
imagefilledrectangle($grand, $e(122), $e(104), $e(134), $e(180), $rouge);
imagefilledellipse($grand, $e(128), $e(184), $e(38), $e(38), $rouge);

$icone = imagecreatetruecolor(TAILLE, TAILLE);
imagesavealpha($icone, true);
imagealphablending($icone, false);
imagefill($icone, 0, 0, imagecolorallocatealpha($icone, 0, 0, 0, 127));
imagecopyresampled($icone, $grand, 0, 0, 0, 0, TAILLE, TAILLE, TAILLE * ECHELLE, TAILLE * ECHELLE);

$cible = __DIR__ . '/../plugin_info/thermostatbe_icon.png';
imagepng($icone, $cible);
echo "Écrit : $cible\n";
