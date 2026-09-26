# Thermostat BE

Le thermostat de la maison, pour une chaudière et une clim réversible.

Le plugin décide seul, chaque minute et à chaque changement d'une sonde, s'il
faut chauffer, refroidir ou ne rien faire, et avec quel appareil. Chaudière et
clim sont des commandes d'autres plugins : le relais Shelly de la chaudière, la
clim arrivée par Tuya, ou tout autre appareil.

## Le problème qu'il règle

Un thermostat réversible naïf ne regarde que la température intérieure. En
janvier, la chaudière chauffe le salon à 24 °C ; le soleil entre, il fait
25 °C, et la clim démarre en froid. On paie deux fois pour revenir au même
point.

Le plugin sépare trois décisions.

1. **La saison** — a-t-on le droit de chauffer, ou de refroidir ? Elle se lit
   **dehors**, sur une moyenne lissée d'environ un jour : saison de chauffe
   sous 15 °C, saison de froid au-dessus de 20 °C, et entre les deux on garde
   la saison en cours. 25 °C au salon un jour de janvier ne changent rien : on
   arrête de chauffer, c'est tout.
2. **Le besoin** — faut-il chauffer ou refroidir maintenant ? Deux consignes
   (20 °C et 25 °C par défaut) avec une **zone neutre** entre elles, et une
   hystérésis autour de chacune.
3. **L'appareil** — chaudière ou clim pour chauffer : imposé, ou choisi au prix
   du kWh de chaleur.

Et des garde-fous qui retardent une décision sans jamais en inventer une :

- **Verrou chaud ↔ froid** : après avoir chauffé, pas de froid pendant 12 h, et
  inversement (mode Auto uniquement).
- **Durées minimales** de marche et d'arrêt pour chaque appareil : pas de
  chaudière relancée toutes les deux minutes.
- **La clim s'arrête avant de changer de mode**, et attend 10 minutes.
- **Jamais deux appareils ensemble** : un seul état à la fois, par
  construction.

## Mise en place

### Onglet Thermostat

- **Sondes intérieures** : une ou plusieurs. Le thermostat régule sur leur
  moyenne. Une sonde silencieuse depuis plus de 3 heures est écartée ; si
  aucune ne répond, tout est coupé et un message le signale.
- **Sonde extérieure** : une station météo ou le plugin IRM. Sans elle, le
  thermostat reste en saison de chauffe et chauffe à la chaudière.
- **Fenêtres** (facultatif) : ouvertes plus d'une minute, le thermostat se met
  en pause et reprend seul à la fermeture.

**Notifications** (facultatif) : une commande de message (Telegram, SMS,
appli mobile). Un message par épisode : sondes perdues puis revenues, fenêtre
ouverte depuis plus de 30 minutes (réglable, 0 pour jamais), relais de la
chaudière ou clim qui ne suit pas les ordres.

Deux surveillances notent au journal et préviennent :

- **Un autre pilote pour la chaudière** : si le relais change d'état sans ordre
  du thermostat (tablette Home Assistant, scénario oublié, appli Shelly), le
  changement est signalé — au plus un message par demi-heure — et le thermostat
  reprend la main aussitôt. Il faut avoir choisi l'*État du relais*.
- **Chauffe sans effet** : un appareil qui tourne depuis 90 minutes (réglable,
  0 pour jamais) sans que la température ait gagné 0,3 °C — ou perdu, en
  froid. Fenêtre ouverte sans capteur, chaudière en défaut, sonde mal placée.

Le panneau **En ce moment** montre la dernière décision et sa raison, en toutes
lettres : « Froid bloqué : on chauffait encore il y a peu, verrou encore
7 h 12 ».

Le **Journal**, juste en dessous, garde les 30 derniers événements avec leur
heure : changements d'état et leur raison, réglages modifiés (mode, consignes,
boost, programmation), conditions devenues vraies ou levées, essais, alertes.
C'est là qu'on comprend pourquoi la maison a chauffé à 3 h du matin.

### Onglet Appareils

**Chaudière** : les commandes *Allumer* et *Éteindre* du relais, et son état
(facultatif).

> Réglez l'**Auto OFF** du Shelly à 15 minutes. Le thermostat renvoie l'ordre
> toutes les 5 minutes : tant que Jeedom tourne, le relais reste dans l'état
> voulu ; si Jeedom s'arrête, la chaudière s'éteint d'elle-même.

**Clim** (facultatif) : *Allumer*, *Éteindre*, *Mode chaud*, *Mode froid*,
*Consigne*, *État*. Si le mode est une liste (cas des clims Tuya), indiquez la
valeur à choisir (`heat`, `cold`…) dans le champ à côté. La clim est pilotée
par son mode et sa consigne et module sa puissance elle-même ; elle n'est
jamais relancée à l'aveugle, car elle bipe à chaque ordre.

Tant que la clim n'est pas configurée, le thermostat chauffe à la chaudière et
ne refroidit pas.

**Consigne envoyée à la clim** (onglet Réglages, Protections) : par défaut, la
consigne du thermostat plus un décalage (+1 °C en chaud, −1 °C en froid). Avec
une **consigne fixe** — facultative, par exemple 30 °C en chaud et 16 °C en
froid —, la clim reçoit toujours cette valeur, tourne à fond et c'est le
thermostat, sur ses propres sondes, qui l'arrête. C'est le réglage d'une clim
pilotée par sonde déportée : sa propre sonde, près du plafond, la couperait
trop tôt. La valeur reste bornée par la plage de la clim (16 à 30 °C par
défaut).

**Essai** : les boutons de chaque appareil l'allument, l'éteignent ou changent
son mode avec les commandes enregistrées — c'est la façon de vérifier qu'on a
choisi les bonnes. Le thermostat laisse faire 2 minutes, puis remet chaque
appareil dans l'état qu'il a décidé.

**Consommation** (facultatif) : la puissance gaz de la chaudière brûleur
allumé, la puissance électrique moyenne de la clim. Avec les prix de l'onglet
Réglages, elles donnent le coût estimé du jour.

**Corriger la télécommande** : coché, une clim dont l'état remonté contredit le
thermostat reçoit à nouveau l'ordre (au plus toutes les 5 minutes) — c'est ce
qui rattrape une commande perdue. Décoché, on peut l'allumer ou l'éteindre à la
télécommande sans que le thermostat la remette dans son état.

### Onglet Réglages

La partie **Marche** — mode, source, consignes, préréglage — s'applique
**immédiatement**, sans passer par « Sauvegarder », comme depuis le tableau de
bord. Un formulaire resté ouvert ne peut donc pas renvoyer, en l'enregistrant,
un mode ou une consigne d'il y a une heure.

| Réglage | Défaut | Rôle |
|---|---|---|
| Mode | Auto | Auto, Chauffage, Refroidissement, Hors-gel, Arrêt |
| Chauffer avec | Auto | Auto (le moins cher), la chaudière, la clim |
| Consigne chauffe / froid | 20 / 25 °C | Écart minimum de 2 °C tenu automatiquement |
| Hors-gel | 7 °C | Tenu dans tous les modes sauf Arrêt |
| Hystérésis | 0,3 °C (chaudière), 0,5 °C (clim) | |
| Saison | chauffe < 15 °C, froid > 20 °C | Sur la moyenne extérieure |
| Verrou chaud ↔ froid | 12 h | |

### Onglet Conditions

Une condition vraie change ce que fait le thermostat tant qu'elle reste vraie.
L'expression s'écrit comme dans un scénario :

| Exemple | Expression | Alors |
|---|---|---|
| Alarme armée | `#[Maison][Alarme][Actif]# == 1` | Ne pas chauffer |
| Personne à la maison | `#[Maison][Présence][Personne]# == 0` | Consignes Absent |
| Heures pleines | `#[Maison][Compteur][Tarif]# == "HP"` | Ne pas utiliser la clim |
| Vacances | `#[Maison][Mode][Vacances]# == 1` | Hors-gel seulement |

Effets possibles : *Tout arrêter*, *Hors-gel seulement*, *Consignes Éco*,
*Consignes Absent*, *Ne pas chauffer*, *Ne pas refroidir*, *Ne pas utiliser la
clim*, *Ne pas utiliser la chaudière*.

- Le thermostat réagit dès qu'une commande citée change.
- Plusieurs conditions vraies s'additionnent ; *Tout arrêter* l'emporte sur
  *Hors-gel*, et entre Éco et Absent on garde la consigne la plus sobre.
- Le **hors-gel reste assuré** avec tous les effets sauf *Tout arrêter*.
- Les consignes affichées au tableau de bord restent les vôtres ; la raison
  dit quelle condition s'applique.
- Une expression que Jeedom ne sait pas calculer est **ignorée** (tenue pour
  fausse) et le journal le signale : une faute de frappe ne coupe pas le
  chauffage.

### Onglet Programmation

Des plages qui changent de préréglage à heure fixe, les jours cochés : Confort
à 6 h 30 en semaine, Éco à 22 h 30 tous les jours. Comme sur un thermostat
mural, une plage est un **changement** : entre deux plages, ce que vous réglez à
la main tient jusqu'au changement suivant. Une plage manquée de plus de
15 minutes (Jeedom arrêté) n'est pas rattrapée.

La case **Programmation active** s'applique immédiatement ; les commandes
*Activer / Suspendre la programmation* font la même chose depuis un scénario
(vacances, invités).

## Boost

Le bouton **Boost** (tuile, page, commande) décale la consigne du côté actif de
2 °C pendant 60 minutes — plus chaud en hiver, plus frais en été. Les deux
valeurs se règlent. Le boost ne passe outre ni la saison, ni les conditions, ni
les sécurités, et s'arrête tout seul.

## Chaudière ou clim : le choix automatique

Avec **Chauffer avec : Auto**, le plugin compare le prix d'un kWh de chaleur :

- chaudière : prix du gaz ÷ rendement (90 %) ;
- clim : prix de l'électricité ÷ COP, le COP étant estimé par une droite entre
  la valeur à 7 °C et celle à −7 °C de la fiche technique.

Il ne change d'appareil que si l'autre est moins cher d'au moins 10 %. Les prix
peuvent être des nombres ou des commandes info (`#[…]#`) pour un tarif
dynamique. Sans prix, la règle est la température extérieure : clim au-dessus
de 5 °C. Sous −5 °C, toujours la chaudière.

### Priorité au surplus solaire (désactivée par défaut)

Cochez **Activer** et choisissez la puissance au compteur (positive quand la
maison importe, négative quand elle exporte — la puissance active du P1
HomeWizard, par exemple). En source Auto, la clim chauffe dès que la maison
exporte plus de 800 W, et continue tant qu'elle importe moins de 300 W : la
clim consomme justement le surplus qui l'a fait démarrer, un seul seuil la
ferait s'arrêter à la minute suivante. Le surplus ne passe outre ni une source
imposée, ni *Ne pas utiliser la clim*, ni le plancher de température de la clim.
Une mesure de plus de 10 minutes est ignorée.

Pour forcer un appareil selon les prix du moment, choisissez simplement
*La chaudière* ou *La clim* : c'est un choix, le plugin le suit.

## Commandes

| Commande | Type | Rôle |
|---|---|---|
| Température | info | Moyenne des sondes intérieures |
| Régler consigne chauffe / froid | action curseur | |
| Choisir le mode | action liste | Auto, Chauffage, Refroidissement, Hors-gel, Arrêt |
| Choisir la source | action liste | Auto, Chaudière, Clim |
| Choisir le préréglage | action liste | Confort, Éco, Absent |
| État | info | Repos, Chauffe (chaudière), Chauffe (clim), Refroidissement (clim) |
| Raison | info | Pourquoi le thermostat fait ce qu'il fait |
| Saison | info | Chauffe ou Froid |
| En marche, Chauffe, Refroidit | info binaire | Historisées |
| Boost, Arrêter le boost | action | Boost actif, Fin du boost en info |
| Activer / Suspendre la programmation | action | Programmation active, Prochain changement en info |
| Chaudière / Clim chaud / Clim froid du jour | info | Minutes de marche, historisées |
| Coût estimé du jour | info | En €, si puissances et prix sont renseignés |
| État (code) | info | 0 repos, 1 chaudière, 2 clim en chaud, 3 clim en froid — historisé, à superposer à la température dans un graphique |
| Consigne effective | info | La consigne réellement appliquée, boost, conditions et hors-gel compris — historisée |
| Conditions actives | info | Les noms des conditions vraies |
| Alerte sondes | info | « 1 sonde muette sur 3 », vide quand toutes répondent |

## Tuile du tableau de bord

La tuile montre la température, l'état (orange en chauffe, bleu en froid), les
deux consignes réglables par crans de 0,5 °C — la consigne du côté actif est
mise en avant, avec la consigne effective quand un boost ou une condition
l'éloigne de la vôtre (« 20,0 → 22,0 ») —, des pastilles *fenêtre ouverte*,
*condition active* et *sonde muette*, le mode, le préréglage, le bouton Boost, la raison de la
décision et, en pied, la température extérieure, la saison et le temps de marche
du jour. Sur mobile, c'est le widget standard de Jeedom.

Les préréglages règlent les deux consignes d'un coup : un scénario ou l'agenda
les appelle pour la nuit ou les absences.

## Sécurités

- Aucune sonde intérieure valide : tout est coupé, un message est posé.
- Thermostat désactivé, supprimé ou plugin désinstallé : l'appareil en marche
  est coupé.
- Le relais de la chaudière qui ne suit pas l'ordre (état remonté) : l'ordre
  est renvoyé et le journal le note.
