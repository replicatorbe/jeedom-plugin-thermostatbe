# Thermostat BE — plugin Jeedom

Le thermostat de la maison, pour une chaudière et une clim réversible.

Il choisit la saison sur la température **extérieure** lissée sur un jour,
jamais sur la température intérieure : 25 °C au salon un après-midi de janvier
ne lancent pas la clim en froid juste après que la chaudière a chauffé. Deux
consignes avec une zone neutre, un verrou de plusieurs heures entre chauffer et
refroidir, des durées minimales de marche et d'arrêt, et le choix entre
chaudière et clim imposé ou fait au prix du kWh de chaleur.

Documentation : [docs/fr_FR/index.md](docs/fr_FR/index.md).

## Développement

```bash
php tests/run.php            # le moteur de décision, hors ligne
php tests/check-classes.php  # les pièges du coeur, contre le Jeedom installé
php tools/make-icon.php      # refait l'icône
```

Toute la décision est dans `core/class/thermostatbeEngine.class.php`, qui ne
connaît pas Jeedom ; `core/class/thermostatbe.class.php` lit les sondes, appelle
le moteur et envoie les ordres.

## Licence

AGPL.
