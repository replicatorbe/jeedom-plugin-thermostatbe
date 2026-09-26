<?php
/* Jeu d'essai hors ligne du plugin Thermostat.
 *
 *   php tests/run.php
 *
 * Aucune dépendance : ni Jeedom, ni base, ni chaudière. Le moteur de décision
 * ignore Jeedom exprès, et c'est ce qui rend ce fichier possible.
 *
 * Un thermostat se trompe en silence : une clim qui refroidit en janvier ce que
 * la chaudière vient de chauffer, une chaudière relancée toutes les deux
 * minutes, rien de tout cela ne lève d'erreur. On s'en aperçoit sur la facture.
 * Chaque scénario ci-dessous rejoue une de ces situations, minute par minute.
 */

date_default_timezone_set('Europe/Brussels');
require_once __DIR__ . '/../core/class/thermostatbeEngine.class.php';

$ok = 0;
$ko = 0;

function verifie($_titre, $_obtenu, $_attendu) {
    global $ok, $ko;
    if ($_obtenu === $_attendu || (is_float($_obtenu) && is_numeric($_attendu) && abs($_obtenu - $_attendu) < 1e-6)) {
        $ok++;
        printf("  %-66s ok\n", $_titre);
        return;
    }
    $ko++;
    printf("  %-66s ÉCHEC : obtenu %s, attendu %s\n", $_titre,
           var_export($_obtenu, true), var_export($_attendu, true));
}

function verifieVrai($_titre, $_condition) {
    verifie($_titre, $_condition ? true : false, true);
}

/* La maison de référence : chaudière et clim réversible configurées. */
function reglages($_extra = array()) {
    return array_merge(array(
        'has_boiler'  => 1,
        'has_ac_heat' => 1,
        'has_ac_cool' => 1,
    ), $_extra);
}

/*
 * Un passage du thermostat, qui garde l'état d'un appel à l'autre comme le
 * fait le plugin avec son cache.
 */
class Maison {
    public $reglages;
    public $etat = array();
    public $now;
    public $dernier;

    public function __construct($_reglages, $_now = null) {
        $this->reglages = $_reglages;
        $this->now = ($_now === null) ? strtotime('2026-01-15 08:00:00') : $_now;
    }

    public function passe($_interieur, $_exterieur = null, $_fenetre = null, $_seed = null) {
        $this->dernier = thermostatbeEngine::decide($this->reglages, array(
            'now'             => $this->now,
            'indoor'          => $_interieur,
            'outdoor'         => $_exterieur,
            'outdoor_seed'    => $_seed,
            'window_open_for' => $_fenetre,
        ), $this->etat);
        $this->etat = $this->dernier['state'];
        return $this->dernier['target'];
    }

    public function avance($_minutes) {
        $this->now += $_minutes * 60;
    }
}

/* ------------------------------------------------------------------ 1 ---
 * Les réglages tapés à la main. */
echo "\nRéglages\n";
verifie('20,5 à la virgule', thermostatbeEngine::number('20,5'), 20.5);
verifie('−5 au signe moins typographique', thermostatbeEngine::number('−5'), -5.0);
verifie('champ vide = null', thermostatbeEngine::number(''), null);
verifie('texte = null', thermostatbeEngine::number('chaud'), null);
$r = thermostatbeEngine::cleanSettings(array('heat_setpoint' => '24', 'cool_setpoint' => '25'));
verifie('écart minimum : la consigne de froid est poussée', $r['cool_setpoint'], 26.0);
verifie('la consigne de chauffe choisie ne bouge pas', $r['heat_setpoint'], 24.0);
verifie('consigne de chauffe bornée', thermostatbeEngine::cleanSettings(array('heat_setpoint' => 80))['heat_setpoint'], 30.0);
verifie('mode inconnu = auto', thermostatbeEngine::cleanSettings(array('mode' => 'turbo'))['mode'], 'auto');
verifie('fitSetpoints : froid baissé pousse la chauffe',
        thermostatbeEngine::fitSetpoints(21, 22, 2, 'cool'), array(20.0, 22.0));
$r = thermostatbeEngine::cleanSettings(array('season_heat_below' => 18, 'season_cool_above' => 16));
verifie('seuils de saison croisés remis dans l\'ordre', $r['season_cool_above'], 19.0);
verifie('prix nul ignoré', thermostatbeEngine::cleanSettings(array('gas_price' => '0'))['gas_price'], null);

/* ------------------------------------------------------------------ 2 ---
 * La saison. */
echo "\nSaison\n";
$s = thermostatbeEngine::cleanSettings(array());
verifie('moyenne 8 °C = chauffe', thermostatbeEngine::season($s, 8.0, null), 'heat');
verifie('moyenne 23 °C = froid', thermostatbeEngine::season($s, 23.0, null), 'cool');
verifie('mi-saison : on garde le froid', thermostatbeEngine::season($s, 17.0, 'cool'), 'cool');
verifie('mi-saison : on garde la chauffe', thermostatbeEngine::season($s, 19.0, 'heat'), 'heat');
verifie('sans mesure dehors : chauffe', thermostatbeEngine::season($s, null, null), 'heat');

/* Un après-midi à 22 °C au milieu d'une semaine à 8 °C ne fait pas l'été. */
$m = new Maison(reglages());
$m->passe(20.0, 8.0, null, 8.0);
for ($i = 0; $i < 6 * 60; $i += 10) {
    $m->avance(10);
    $m->passe(20.0, 22.0);
}
verifie('6 h à 22 °C dehors : toujours en chauffe', $m->dernier['season'], 'heat');
verifieVrai('la moyenne a bougé sans franchir 15 °C', $m->etat['outdoor_avg'] > 8 && $m->etat['outdoor_avg'] < 15);

/* ------------------------------------------------------------------ 3 ---
 * Le cas qui a motivé le plugin : chauffer jusqu'à 24 °C en hiver, puis
 * monter à 25 °C, ne doit JAMAIS lancer la clim en froid. */
echo "\nHiver : pas de froid après la chauffe\n";
$m = new Maison(reglages(array('heat_setpoint' => 24, 'source' => 'boiler')));
verifie('21 °C : la chaudière chauffe', $m->passe(21.0, 3.0, null, 3.0), 'heat_boiler');
$m->avance(30);
verifie('23,9 °C : toujours en chauffe (hystérésis)', $m->passe(23.9, 3.0), 'heat_boiler');
$m->avance(10);
verifie('24,3 °C : arrêt', $m->passe(24.3, 3.0), 'idle');
$m->avance(60);
verifie('27 °C au soleil : rien, pas de froid', $m->passe(27.0, 3.0), 'idle');
verifieVrai('la raison le dit', strpos($m->dernier['reason'], 'pas de froid en saison de chauffe') !== false);
$m->avance(24 * 60);
verifie('le lendemain, toujours 27 °C : toujours rien', $m->passe(27.0, 3.0), 'idle');

/* ------------------------------------------------------------------ 4 ---
 * L'été, la clim refroidit. */
echo "\nÉté : la clim refroidit\n";
$m = new Maison(reglages(), strtotime('2026-07-15 14:00:00'));
verifie('24 °C dedans : rien', $m->passe(24.0, 30.0, null, 24.0), 'idle');
verifie('saison de froid', $m->dernier['season'], 'cool');
$m->avance(30);
verifie('25,6 °C : la clim refroidit', $m->passe(25.6, 31.0), 'cool_ac');
verifie('consigne clim = 25 − 1', $m->dernier['ac_setpoint'], 24.0);
$m->avance(20);
verifie('24,6 °C : continue (hystérésis de la clim)', $m->passe(24.6, 31.0), 'cool_ac');
$m->avance(10);
verifie('24,4 °C : arrêt', $m->passe(24.4, 31.0), 'idle');
$m->avance(60 * 8);
verifie('nuit d\'été à 19 °C dedans : pas de chauffe', $m->passe(19.0, 14.0), 'idle');

/* Froid facultatif : il fait plus frais dehors que la consigne. */
$m = new Maison(reglages(array('cool_min_outdoor' => 22)), strtotime('2026-07-15 22:00:00'));
$m->passe(20.0, 25.0, null, 24.0);
$m->avance(10);
verifie('26 °C dedans, 18 °C dehors, seuil 22 : pas de clim', $m->passe(26.0, 18.0), 'idle');
verifie('statut bloqué', $m->dernier['status'], 'blocked');

/* ------------------------------------------------------------------ 5 ---
 * Le verrou entre chauffer et refroidir, en automatique. */
echo "\nVerrou de changement de saison\n";
$m = new Maison(reglages(array('source' => 'boiler')), strtotime('2026-05-10 07:00:00'));
verifie('matin frais : chauffe', $m->passe(18.0, 10.0, null, 14.0), 'heat_boiler');
$m->avance(30);
verifie('arrêt', $m->passe(20.4, 12.0), 'idle');
/* La saison bascule (moyenne forcée au-dessus du seuil). */
$m->etat['outdoor_avg'] = 21.0;
$m->avance(4 * 60);
verifie('4 h après, 27 °C et saison froid : froid bloqué', $m->passe(27.0, 28.0), 'idle');
verifieVrai('la raison parle du verrou', strpos($m->dernier['reason'], 'verrou') !== false);
$m->avance(9 * 60);
verifie('13 h après la chauffe : la clim refroidit', $m->passe(27.0, 28.0), 'cool_ac');

/* Le verrou n'existe qu'en automatique : un mode imposé est une décision. */
$m = new Maison(reglages(array('mode' => 'heat', 'source' => 'boiler')));
$m->passe(18.0, 5.0, null, 5.0);
$m->avance(30);
$m->passe(20.5, 5.0);
$m->reglages['mode'] = 'cool';
$m->avance(10);
verifie('mode froid imposé juste après la chauffe : la clim démarre', $m->passe(26.0, 5.0), 'cool_ac');

/* ------------------------------------------------------------------ 6 ---
 * Anti-court-cycle. */
echo "\nDurées minimales\n";
$m = new Maison(reglages(array('source' => 'boiler', 'boiler_min_on' => 5, 'boiler_min_off' => 5)));
verifie('19 °C : chauffe', $m->passe(19.0, 3.0, null, 3.0), 'heat_boiler');
$m->avance(2);
verifie('saut à 21 °C après 2 min : maintenue', $m->passe(21.0, 3.0), 'heat_boiler');
verifieVrai('raison : durée minimale de marche', strpos($m->dernier['reason'], 'durée minimale de marche') !== false);
$m->avance(3);
verifie('5 min : arrêt', $m->passe(21.0, 3.0), 'idle');
$m->avance(2);
verifie('rechute à 19 °C 2 min après : attente', $m->passe(19.0, 3.0), 'idle');
verifie('statut en attente', $m->dernier['status'], 'waiting');
$m->avance(3);
verifie('5 min après l\'arrêt : redémarre', $m->passe(19.0, 3.0), 'heat_boiler');

/* ------------------------------------------------------------------ 7 ---
 * Sécurités : elles coupent sans attendre la durée minimale. */
echo "\nSécurités\n";
$m = new Maison(reglages(array('source' => 'boiler')));
$m->passe(19.0, 3.0, null, 3.0);
$m->avance(1);
verifie('sonde perdue : arrêt immédiat', $m->passe(null, 3.0), 'idle');
verifie('statut sécurité', $m->dernier['status'], 'safety');

$m = new Maison(reglages(array('source' => 'boiler', 'window_delay' => 60)));
$m->passe(19.0, 3.0, null, 3.0);
$m->avance(1);
verifie('fenêtre ouverte depuis 30 s : on continue', $m->passe(19.0, 3.0, 30), 'heat_boiler');
$m->avance(1);
verifie('fenêtre ouverte depuis 90 s : arrêt immédiat', $m->passe(19.0, 3.0, 90), 'idle');
verifie('statut fenêtre', $m->dernier['status'], 'window');

$m = new Maison(reglages(array('source' => 'boiler')));
$m->passe(19.0, 3.0, null, 3.0);
$m->reglages['mode'] = 'off';
$m->avance(1);
verifie('thermostat coupé : arrêt immédiat', $m->passe(19.0, 3.0), 'idle');

/* Hors-gel, y compris en plein été et malgré le verrou. */
$m = new Maison(reglages(array('mode' => 'frost', 'source' => 'boiler')));
verifie('mode hors-gel à 12 °C : rien', $m->passe(12.0, -5.0, null, -5.0), 'idle');
$m->avance(60);
verifie('mode hors-gel à 6,5 °C : chauffe', $m->passe(6.5, -8.0), 'heat_boiler');
verifie('consigne hors-gel', $m->dernier['setpoint'], 7.0);

$m = new Maison(reglages(array('source' => 'boiler')), strtotime('2026-07-15 14:00:00'));
$m->passe(26.0, 30.0, null, 25.0);
$m->avance(20);
$m->passe(6.0, 30.0);
verifie('saison de froid, 6 °C dedans : hors-gel quand même', $m->dernier['target'], 'heat_boiler');

/* ------------------------------------------------------------------ 8 ---
 * Choix de l'appareil de chauffe. */
echo "\nChaudière ou clim\n";
$s = thermostatbeEngine::cleanSettings(reglages(array('source' => 'boiler')));
verifie('chaudière imposée', thermostatbeEngine::chooseSource($s, 12.0, null)['source'], 'boiler');
$s = thermostatbeEngine::cleanSettings(reglages(array('source' => 'ac')));
verifie('clim imposée', thermostatbeEngine::chooseSource($s, -10.0, null)['source'], 'ac');
$s = thermostatbeEngine::cleanSettings(array('source' => 'ac', 'has_boiler' => 1));
verifie('clim imposée mais absente : rien', thermostatbeEngine::chooseSource($s, 10.0, null)['source'], null);
$s = thermostatbeEngine::cleanSettings(array('source' => 'auto', 'has_boiler' => 1));
verifie('auto sans clim : chaudière', thermostatbeEngine::chooseSource($s, 15.0, null)['source'], 'boiler');

$s = thermostatbeEngine::cleanSettings(reglages());
verifie('auto sans prix, 10 °C dehors : clim', thermostatbeEngine::chooseSource($s, 10.0, null)['source'], 'ac');
verifie('auto sans prix, 2 °C dehors : chaudière', thermostatbeEngine::chooseSource($s, 2.0, null)['source'], 'boiler');
verifie('auto, 5,5 °C, venant de la chaudière : on la garde', thermostatbeEngine::chooseSource($s, 5.5, 'boiler')['source'], 'boiler');
verifie('auto, 4,5 °C, venant de la clim : on la garde', thermostatbeEngine::chooseSource($s, 4.5, 'ac')['source'], 'ac');
verifie('auto, extérieur inconnu : chaudière', thermostatbeEngine::chooseSource($s, null, null)['source'], 'boiler');
verifie('auto, −8 °C sous le plancher de la clim : chaudière', thermostatbeEngine::chooseSource($s, -8.0, null)['source'], 'boiler');

/* Au prix : gaz 0,10 €/kWh à 90 %, électricité 0,30 €/kWh. */
$s = thermostatbeEngine::cleanSettings(reglages(array('gas_price' => '0,10', 'elec_price' => '0,30')));
verifie('COP à 7 °C', thermostatbeEngine::cop($s, 7.0), 3.5);
verifie('COP à −7 °C', thermostatbeEngine::cop($s, -7.0), 2.0);
verifie('COP à 0 °C', thermostatbeEngine::cop($s, 0.0), 2.75);
verifie('au prix, 10 °C : clim (0,30/3,82 < 0,111)', thermostatbeEngine::chooseSource($s, 10.0, null)['source'], 'ac');
verifie('au prix, −3 °C : chaudière (0,30/2,43 > 0,111)', thermostatbeEngine::chooseSource($s, -3.0, null)['source'], 'boiler');
/* Autour de l'égalité, on garde l'appareil en cours. 0,111 € de gaz contre
 * 0,30/COP : égalité vers COP 2,7, soit −0,5 °C. */
verifie('au prix, 1 °C, venant de la chaudière : marge, on la garde',
        thermostatbeEngine::chooseSource($s, 1.0, 'boiler')['source'], 'boiler');
verifie('au prix, 1 °C, sans historique : clim, un peu moins chère',
        thermostatbeEngine::chooseSource($s, 1.0, null)['source'], 'ac');

/* Pas de chaudière et clim ensemble : le passage de l'une à l'autre attend
 * que la clim puisse démarrer, sans laisser la maison sans chauffage. */
$m = new Maison(reglages(array('ac_min_off' => 10)));
$m->etat['ac_off_at'] = $m->now - 60;
verifie('1 °C : chaudière', $m->passe(19.0, 1.0, null, 1.0), 'heat_boiler');
$m->avance(6);
verifie('dehors 12 °C, clim arrêtée il y a 7 min : la chaudière continue', $m->passe(19.5, 12.0), 'heat_boiler');
$m->avance(4);
verifie('clim prête : relève', $m->passe(19.5, 12.0), 'heat_ac');

/* ------------------------------------------------------------------ 9 ---
 * La clim ne passe jamais directement de chaud à froid. */
echo "\nClim : changement de mode\n";
$m = new Maison(reglages(array('mode' => 'heat', 'source' => 'ac', 'ac_min_on' => 0, 'ac_mode_delay' => 10)));
verifie('chauffe par la clim', $m->passe(19.0, 10.0, null, 10.0), 'heat_ac');
verifie('consigne clim = 20 + 1', $m->dernier['ac_setpoint'], 21.0);
$m->reglages['mode'] = 'cool';
$m->avance(1);
verifie('passage en froid : d\'abord l\'arrêt', $m->passe(27.0, 10.0), 'idle');
$m->avance(5);
verifie('5 min après : toujours arrêtée', $m->passe(27.0, 10.0), 'idle');
verifieVrai('raison : changement de mode', strpos($m->dernier['reason'], 'changer de mode') !== false);
$m->avance(5);
verifie('10 min après : froid', $m->passe(27.0, 10.0), 'cool_ac');

/* ----------------------------------------------------------------- 10 ---
 * Aucun appareil pour refroidir : le plugin le dit, sans rien faire. */
echo "\nConfiguration incomplète\n";
$m = new Maison(array('has_boiler' => 1, 'mode' => 'cool'));
verifie('froid sans clim : rien', $m->passe(28.0, 30.0, null, 25.0), 'idle');
verifie('statut bloqué', $m->dernier['status'], 'blocked');
$m = new Maison(array('has_boiler' => 1, 'source' => 'auto'));
verifie('chaudière seule, clim pas encore installée : chauffe', $m->passe(18.0, 12.0, null, 12.0), 'heat_boiler');

/* ----------------------------------------------------------------- 11 ---
 * Textes. */
echo "\nTextes\n";
verifie('durée en minutes', thermostatbeEngine::formatDuration(125), '3 min');
verifie('durée en heures', thermostatbeEngine::formatDuration(3 * 3600 + 5 * 60), '3 h 05');
verifie('température', thermostatbeEngine::formatTemperature(20.46), '20,5 °C');

printf("\n%d vérification(s), %d échec(s)\n", $ok + $ko, $ko);
exit($ko > 0 ? 1 : 0);
