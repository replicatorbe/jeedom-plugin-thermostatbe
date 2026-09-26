<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-thermometer-half"></i> {{Sondes}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Sonde muette après}}</label>
			<div class="col-md-2">
				<div class="input-group">
					<input type="number" min="0" max="1440" class="configKey form-control roundedLeft" data-l1key="sensor_max_age" placeholder="180">
					<span class="input-group-addon roundedRight">{{min}}</span>
				</div>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Minutes sans nouvelle mesure au-delà desquelles une sonde intérieure est écartée de la moyenne. Beaucoup de sondes ne publient qu'au changement : trop court, ce délai couperait le chauffage une nuit calme sur une sonde en parfait état. Une sonde à la pile vide ne se tait pas : Jeedom garde sa dernière valeur, et le thermostat chaufferait sur une mesure de la veille. Si plus aucune sonde ne répond, le thermostat coupe tout et le signale dans le centre de messages. 0 désactive le contrôle.}}</span>
			</div>
		</div>
	</fieldset>
	<fieldset>
		<legend><i class="fas fa-fire"></i> {{Chaudière}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Renvoyer l'ordre toutes les}}</label>
			<div class="col-md-2">
				<div class="input-group">
					<input type="number" min="0" max="60" class="configKey form-control roundedLeft" data-l1key="boiler_resend" placeholder="5">
					<span class="input-group-addon roundedRight">{{min}}</span>
				</div>
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Le relais de la chaudière reçoit à nouveau son ordre — marche ou arrêt — à cet intervalle. Combiné à l'« Auto OFF » du Shelly réglé à 15 minutes, c'est la sécurité qui éteint la chaudière si Jeedom s'arrête. La clim, elle, n'est jamais relancée à l'aveugle : elle bipe à chaque commande. 0 désactive le renvoi.}}</span>
			</div>
		</div>
	</fieldset>
</form>
