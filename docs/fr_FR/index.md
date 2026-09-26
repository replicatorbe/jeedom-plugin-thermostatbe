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
  moyenne. Une sonde silencieuse depuis plus de 90 minutes est écartée ; si
  aucune ne répond, tout est coupé et un message le signale.
- **Sonde extérieure** : une station météo ou le plugin IRM. Sans elle, le
  thermostat reste en saison de chauffe et chauffe à la chaudière.
- **Fenêtres** (facultatif) : ouvertes plus d'une minute, le thermostat se met
  en pause et reprend seul à la fermeture.

Le panneau **En ce moment** montre la dernière décision et sa raison, en toutes
lettres : « Froid bloqué : on chauffait encore il y a peu, verrou encore
7 h 12 ».

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

### Onglet Réglages

| Réglage | Défaut | Rôle |
|---|---|---|
| Mode | Auto | Auto, Chauffage, Refroidissement, Hors-gel, Arrêt |
| Chauffer avec | Auto | Auto (le moins cher), la chaudière, la clim |
| Consigne chauffe / froid | 20 / 25 °C | Écart minimum de 2 °C tenu automatiquement |
| Hors-gel | 7 °C | Tenu dans tous les modes sauf Arrêt |
| Hystérésis | 0,3 °C (chaudière), 0,5 °C (clim) | |
| Saison | chauffe < 15 °C, froid > 20 °C | Sur la moyenne extérieure |
| Verrou chaud ↔ froid | 12 h | |

## Chaudière ou clim : le choix automatique

Avec **Chauffer avec : Auto**, le plugin compare le prix d'un kWh de chaleur :

- chaudière : prix du gaz ÷ rendement (90 %) ;
- clim : prix de l'électricité ÷ COP, le COP étant estimé par une droite entre
  la valeur à 7 °C et celle à −7 °C de la fiche technique.

Il ne change d'appareil que si l'autre est moins cher d'au moins 10 %. Les prix
peuvent être des nombres ou des commandes info (`#[…]#`) pour un tarif
dynamique. Sans prix, la règle est la température extérieure : clim au-dessus
de 5 °C. Sous −5 °C, toujours la chaudière.

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

Les préréglages règlent les deux consignes d'un coup : un scénario ou l'agenda
les appelle pour la nuit ou les absences.

## Sécurités

- Aucune sonde intérieure valide : tout est coupé, un message est posé.
- Thermostat désactivé, supprimé ou plugin désinstallé : l'appareil en marche
  est coupé.
- Le relais de la chaudière qui ne suit pas l'ordre (état remonté) : l'ordre
  est renvoyé et le journal le note.
