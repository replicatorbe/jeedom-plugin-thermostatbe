# Changelog

## 0.3

- **Tuile du tableau de bord** : température, état, deux consignes réglables au
  doigt, mode, préréglage, boost, raison et temps de marche du jour.
- **Boost** : +2 °C (ou −2 °C en froid) pendant 60 minutes, réglables.
- **Programmation horaire** : préréglages par jour et par heure, suspendable
  par commande.
- **Essai des appareils** depuis la page.
- **Notifications** : sondes perdues, fenêtre ouverte trop longtemps, appareil
  qui ne suit pas les ordres.
- **Temps de marche et coût estimé du jour**, historisés.
- **Priorité au surplus solaire**, facultative et désactivée par défaut.
- Le préréglage « Manuel » apparaît dans la liste.

## 0.2

- Onglet **Conditions** : une expression vraie (alarme armée, maison vide,
  heures pleines…) arrête le thermostat, le met en hors-gel, applique les
  consignes Éco ou Absent, ou interdit de chauffer, de refroidir, la clim ou la
  chaudière. Le thermostat réagit dès que la commande change.
- La marche (mode, source, consignes, préréglage) s'applique immédiatement
  depuis la page : enregistrer un formulaire ouvert depuis longtemps ne rétablit
  plus d'anciennes valeurs.
- Le hors-gel est tenu dans tous les modes, même avec une consigne réglée
  en dessous.
- Une clim maintenue quelques minutes par sa durée minimale de marche garde sa
  consigne, au lieu de recevoir celle du besoin suivant.
- Après un redémarrage de Jeedom, la moyenne extérieure n'est plus remplacée par
  la première mesure : la saison ne bascule plus sur un après-midi doux.
- Sondes muettes après 3 heures au lieu de 90 minutes : les sondes qui ne
  publient qu'au changement ne coupent plus le chauffage une nuit calme.
- Option « Corriger la télécommande » pour la clim.
- Un thermostat sans sonde choisie le dit, au lieu de signaler une panne.
- L'état du thermostat est conservé hors de l'équipement : le cron ne peut plus
  écraser un réglage enregistré au même moment.

## 0.1

Première version.

- Saison lue sur la moyenne extérieure lissée, verrou chaud ↔ froid.
- Deux consignes avec zone neutre, hystérésis, hors-gel.
- Chaudière, clim ou choix automatique au prix du kWh de chaleur.
- Durées minimales de marche et d'arrêt, arrêt de la clim avant changement de
  mode.
- Plusieurs sondes intérieures moyennées, pause sur fenêtre ouverte, coupure de
  sécurité sans sonde.
