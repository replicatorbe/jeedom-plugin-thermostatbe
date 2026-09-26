<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('thermostatbe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());

/*
 * Un champ qui désigne une commande d'un autre plugin : la zone de texte, et
 * le bouton qui ouvre le sélecteur du coeur. Le coeur traduit tout seul les
 * « #[Salon][Sonde][Température]# » en identifiants à l'enregistrement, et
 * inversement à l'ouverture : un renommage de la pièce ne casse donc rien.
 *
 * $_multi : le champ accepte plusieurs commandes, séparées par des espaces —
 * plusieurs sondes intérieures, plusieurs fenêtres.
 */
function thermostatbeCmdField($_key, $_label, $_type, $_multi, $_help, $_placeholder = '') {
	?>
	<div class="form-group">
		<label class="col-sm-4 control-label"><?php echo $_label; ?>
			<?php if ($_help != '') { ?>
				<sup><i class="fas fa-question-circle" title="<?php echo $_help; ?>"></i></sup>
			<?php } ?>
		</label>
		<div class="col-sm-7">
			<div class="input-group">
				<input type="text" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" placeholder="<?php echo $_placeholder; ?>">
				<span class="input-group-btn">
					<a class="btn btn-default tbPickCmd" data-key="<?php echo $_key; ?>" data-type="<?php echo $_type; ?>" data-multi="<?php echo $_multi ? 1 : 0; ?>" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a>
					<a class="btn btn-default roundedRight tbClearCmd" data-key="<?php echo $_key; ?>" title="{{Vider}}"><i class="fas fa-times"></i></a>
				</span>
			</div>
		</div>
	</div>
	<?php
}

/* Un réglage numérique. En texte et non en « number » : un navigateur en
 * français vide en silence « 20,5 » dans un champ numérique. Le serveur relit
 * la virgule. */
function thermostatbeNumber($_key, $_label, $_unit, $_placeholder, $_help = '') {
	?>
	<div class="form-group">
		<label class="col-sm-5 control-label"><?php echo $_label; ?>
			<?php if ($_help != '') { ?>
				<sup><i class="fas fa-question-circle" title="<?php echo $_help; ?>"></i></sup>
			<?php } ?>
		</label>
		<div class="col-sm-4">
			<div class="input-group">
				<input type="text" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="<?php echo $_key; ?>" placeholder="<?php echo $_placeholder; ?>">
				<span class="input-group-addon roundedRight"><?php echo $_unit; ?></span>
			</div>
		</div>
	</div>
	<?php
}
?>
<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter un thermostat}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>
		<legend><i class="fas fa-thermometer-half"></i> {{Mes thermostats}}</legend>
		<?php
		if (count($eqLogics) == 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucun thermostat pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Cliquez sur « Ajouter un thermostat » et nommez-le, par exemple « Maison ».}}</li>';
			echo '<li>{{Onglet « Thermostat » : choisissez les sondes intérieures (une ou plusieurs, le thermostat en fait la moyenne) et la sonde extérieure.}}</li>';
			echo '<li>{{Onglet « Appareils » : les commandes marche et arrêt du relais de la chaudière et, quand elle sera dans Jeedom, celles de la clim.}}</li>';
			echo '<li>{{Onglet « Réglages » : les consignes. Les valeurs proposées conviennent pour commencer.}}</li>';
			echo '</ol>';
			echo '</div>';
		}
		?>
		<div class="eqLogicThumbnailContainer">
			<?php
			foreach ($eqLogics as $eqLogic) {
				$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
				echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
				echo '<img src="' . $plugin->getPathImgIcon() . '">';
				echo '<br>';
				echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
				echo '</div>';
			}
			?>
		</div>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-thermometer-half"></i><span class="hidden-xs"> {{Thermostat}}</span></a></li>
			<li role="presentation"><a href="#devicetab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-fire"></i><span class="hidden-xs"> {{Appareils}}</span></a></li>
			<li role="presentation"><a href="#settingstab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-sliders-h"></i><span class="hidden-xs"> {{Réglages}}</span></a></li>
			<li role="presentation"><a href="#scheduletab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-clock"></i><span class="hidden-xs"> {{Programmation}}</span></a></li>
			<li role="presentation"><a href="#conditiontab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-user-shield"></i><span class="hidden-xs"> {{Conditions}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================================== THERMOSTAT ========================================== -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Nom du thermostat}}</label>
								<div class="col-sm-7">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Maison}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Objet parent}}</label>
								<div class="col-sm-7">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach (jeeObject::buildTree(null, false) as $object) {
											echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Activer}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
								<label class="col-sm-2 control-label">{{Visible}}</label>
								<div class="col-sm-2">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-thermometer-half"></i> {{Sondes}}</legend>
							<?php
							thermostatbeCmdField('indoor_sensors', '{{Sondes intérieures}}', 'info', true,
								'{{Une ou plusieurs sondes : le thermostat régule sur leur moyenne. Une sonde muette est écartée de la moyenne ; si aucune ne répond, tout est coupé par sécurité.}}');
							thermostatbeCmdField('outdoor_sensor', '{{Sonde extérieure}}', 'info', false,
								'{{Elle décide de la saison, sur une moyenne lissée d\'environ un jour, et du choix entre chaudière et clim. Une station météo ou le plugin IRM conviennent. Sans elle, le thermostat reste en saison de chauffe et chauffe à la chaudière.}}');
							thermostatbeCmdField('windows', '{{Fenêtres}}', 'info', true,
								'{{Facultatif. Une fenêtre ouverte plus longtemps que le délai réglé met le thermostat en pause, qui reprend tout seul à la fermeture.}}');
							?>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Fenêtre ouverte vaut 0}}</label>
								<div class="col-sm-7">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="window_invert">
									<span class="help-block" style="margin:0;">{{Par défaut, 1 veut dire ouverte. Cochez si vos capteurs disent l'inverse.}}</span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-bell"></i> {{Notifications}}</legend>
							<?php
							thermostatbeCmdField('notify_cmd', '{{Prévenir par}}', 'action', false,
								'{{Facultatif. Une commande de message : Telegram, SMS, notification de l\'appli mobile. Un message par épisode — sondes perdues puis revenues, fenêtre ouverte trop longtemps, chaudière ou clim qui ne suit pas les ordres.}}');
							?>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Fenêtre ouverte depuis}}</label>
								<div class="col-sm-3">
									<div class="input-group">
										<input type="text" class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="notify_window" placeholder="30">
										<span class="input-group-addon roundedRight">{{min}}</span>
									</div>
								</div>
								<div class="col-sm-4">
									<span class="help-block" style="margin:0;">{{0 : jamais de message pour une fenêtre.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
				<div class="col-lg-6">
					<legend><i class="fas fa-heartbeat"></i> {{En ce moment}}
						<a class="btn btn-default btn-xs pull-right" id="bt_thermostatbeRefresh"><i class="fas fa-sync"></i> {{Évaluer maintenant}}</a>
						<a class="btn btn-default btn-xs pull-right" id="bt_thermostatbeBoost" style="margin-right:6px;"><i class="fas fa-rocket"></i> <span>{{Boost}}</span></a>
					</legend>
					<div id="div_thermostatbeStatus" class="well well-sm">{{Enregistrez le thermostat pour voir sa première décision.}}</div>
				</div>
			</div>

			<!-- ========================================== APPAREILS ========================================== -->
			<div role="tabpanel" class="tab-pane" id="devicetab">
				<br>
				<div class="alert alert-info" style="margin:5px 5px 10px 5px;">
					{{Les boutons « Essai » commandent réellement l'appareil, avec les commandes enregistrées : sauvegardez d'abord. Pendant 2 minutes, le thermostat laisse faire ; ensuite, il remet chaque appareil dans l'état qu'il a décidé.}}
				</div>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-fire"></i> {{Chaudière}}</legend>
							<?php
							thermostatbeCmdField('boiler_on', '{{Allumer}}', 'action', false, '{{La commande qui ferme le relais, par exemple « On » du Shelly.}}');
							thermostatbeCmdField('boiler_off', '{{Éteindre}}', 'action', false, '');
							thermostatbeCmdField('boiler_state', '{{État du relais}}', 'info', false,
								'{{Facultatif. Si le relais ne suit pas l\'ordre, le thermostat le renvoie et le note dans le journal.}}');
							?>
							<div class="form-group">
								<div class="col-sm-11 col-sm-offset-1">
									<span class="help-block" style="margin:0;">{{Conseil : réglez l'« Auto OFF » du Shelly à 15 minutes. Le thermostat lui renvoie l'ordre toutes les 5 minutes pendant la chauffe : si Jeedom s'arrête, la chaudière s'éteint d'elle-même.}}</span>
								</div>
							</div>
							<?php
							thermostatbeNumber('boiler_power', '{{Consommation en marche}}', 'kW',
								'', '{{Facultatif, pour le coût du jour : la puissance gaz consommée brûleur allumé (sur la plaque de la chaudière). Multipliée par le prix du gaz.}}');
							?>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Essai}}</label>
								<div class="col-sm-8">
									<a class="btn btn-default btn-sm tbTest" data-device="boiler" data-do="on"><i class="fas fa-fire"></i> {{Allumer}}</a>
									<a class="btn btn-default btn-sm tbTest" data-device="boiler" data-do="off"><i class="fas fa-power-off"></i> {{Éteindre}}</a>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-snowflake"></i> {{Clim réversible}}</legend>
							<div class="form-group">
								<div class="col-sm-11 col-sm-offset-1">
									<span class="help-block" style="margin:0;">{{Facultatif : sans clim, le thermostat chauffe à la chaudière et ne refroidit pas. La clim est pilotée par son mode et sa consigne ; elle module sa puissance elle-même.}}</span>
								</div>
							</div>
							<?php
							thermostatbeCmdField('ac_on', '{{Allumer}}', 'action', false, '');
							thermostatbeCmdField('ac_off', '{{Éteindre}}', 'action', false, '');
							thermostatbeCmdField('ac_mode_heat', '{{Mode chaud}}', 'action', false,
								'{{La commande qui passe la clim en chauffage. Si c\'est une liste (Tuya), indiquez la valeur à choisir à côté.}}');
							?>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Valeur du mode chaud}}</label>
								<div class="col-sm-4">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ac_mode_heat_value" placeholder="{{heat}}">
								</div>
							</div>
							<?php
							thermostatbeCmdField('ac_mode_cool', '{{Mode froid}}', 'action', false, '');
							?>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Valeur du mode froid}}</label>
								<div class="col-sm-4">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ac_mode_cool_value" placeholder="{{cold}}">
								</div>
							</div>
							<?php
							thermostatbeCmdField('ac_setpoint', '{{Consigne}}', 'action', false,
								'{{Le curseur de consigne de la clim. Facultatif, mais sans lui la clim régule sur sa propre consigne.}}');
							thermostatbeCmdField('ac_state', '{{État marche/arrêt}}', 'info', false,
								'{{Facultatif. Si la clim ne suit pas l\'ordre, il est renvoyé, au plus toutes les 5 minutes.}}');
							?>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Corriger la télécommande}}</label>
								<div class="col-sm-7">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="ac_enforce" checked>
									<span class="help-block tbAcEnforceHelp" style="margin:0;">{{Coché : si l'état remonté contredit le thermostat, l'ordre est renvoyé — c'est ce qui rattrape une commande perdue. Décoché : quelqu'un peut allumer ou éteindre la clim à la télécommande sans que le thermostat la remette dans son état 5 minutes plus tard ; l'écart est seulement noté dans le journal.}}</span>
								</div>
							</div>
							<?php
							thermostatbeNumber('ac_power', '{{Consommation moyenne}}', 'kW',
								'', '{{Facultatif, pour le coût du jour : la puissance électrique moyenne de la clim en marche. Un compteur ou la fiche technique la donnent ; environ un tiers de sa puissance de chauffe.}}');
							?>
							<div class="form-group">
								<label class="col-sm-4 control-label">{{Essai}}</label>
								<div class="col-sm-8">
									<a class="btn btn-default btn-sm tbTest" data-device="ac" data-do="heat"><i class="fas fa-fire"></i> {{Chaud}}</a>
									<a class="btn btn-default btn-sm tbTest" data-device="ac" data-do="cool"><i class="fas fa-snowflake"></i> {{Froid}}</a>
									<a class="btn btn-default btn-sm tbTest" data-device="ac" data-do="off"><i class="fas fa-power-off"></i> {{Arrêt}}</a>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================================== RÉGLAGES ========================================== -->
			<div role="tabpanel" class="tab-pane" id="settingstab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<!--
						     La marche ne fait pas partie du formulaire : chaque changement
						     s'applique tout de suite, comme depuis le tableau de bord. Un
						     formulaire resté ouvert renverrait sinon, à l'enregistrement,
						     le mode et les consignes du moment où on l'a ouvert — et
						     effacerait ce qu'un scénario a posé entre-temps.
						-->
						<fieldset>
							<legend><i class="fas fa-power-off"></i> {{Marche}} <small class="text-muted">{{appliqué immédiatement}}</small></legend>
							<div class="form-group tbRuntimeNew">
								<div class="col-sm-11 col-sm-offset-1">
									<span class="help-block text-warning" style="margin:0;">{{Enregistrez d'abord le thermostat pour régler sa marche.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-5 control-label">{{Mode}}</label>
								<div class="col-sm-4">
									<select class="form-control tbRuntime" data-key="mode">
										<option value="auto">{{Auto (saison selon l'extérieur)}}</option>
										<option value="heat">{{Chauffage seulement}}</option>
										<option value="cool">{{Refroidissement seulement}}</option>
										<option value="frost">{{Hors-gel}}</option>
										<option value="off">{{Arrêt}}</option>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-5 control-label">{{Chauffer avec}}</label>
								<div class="col-sm-4">
									<select class="form-control tbRuntime" data-key="source">
										<option value="auto">{{Auto (le moins cher)}}</option>
										<option value="boiler">{{La chaudière}}</option>
										<option value="ac">{{La clim}}</option>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-5 control-label">{{Chauffer jusqu'à}}</label>
								<div class="col-sm-4">
									<div class="input-group">
										<input type="text" class="form-control roundedLeft tbRuntime" data-key="heat_setpoint" placeholder="20">
										<span class="input-group-addon roundedRight">°C</span>
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-5 control-label">{{Refroidir à partir de}}</label>
								<div class="col-sm-4">
									<div class="input-group">
										<input type="text" class="form-control roundedLeft tbRuntime" data-key="cool_setpoint" placeholder="25">
										<span class="input-group-addon roundedRight">°C</span>
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-5 control-label">{{Préréglage}}</label>
								<div class="col-sm-4">
									<select class="form-control tbRuntime" data-key="preset">
										<option value="manual">{{Manuel}}</option>
										<option value="comfort">{{Confort}}</option>
										<option value="eco">{{Éco}}</option>
										<option value="away">{{Absent}}</option>
									</select>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-rocket"></i> {{Boost}}</legend>
							<?php
							thermostatbeNumber('boost_delta', '{{Décalage de consigne}}', '°C', '2',
								'{{Ajouté à la consigne de chauffe, retiré de celle de froid, le temps du boost. Le boost ne passe outre ni la saison, ni les conditions, ni la sécurité.}}');
							thermostatbeNumber('boost_minutes', '{{Durée}}', 'min', '60');
							?>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-thermometer-three-quarters"></i> {{Consignes}}</legend>
							<?php
							thermostatbeNumber('frost_setpoint', '{{Hors-gel}}', '°C', '7',
								'{{Tenue dans tous les modes sauf Arrêt, même en saison de froid.}}');
							thermostatbeNumber('min_gap', '{{Écart minimum entre les deux}}', '°C', '2',
								'{{La zone neutre : entre les deux consignes, rien ne tourne. Toucher une consigne pousse l\'autre si l\'écart n\'est plus tenu.}}');
							thermostatbeNumber('hysteresis', '{{Hystérésis chaudière}}', '± °C', '0,3',
								'{{Avec 20 °C et 0,3 : la chaudière démarre à 19,7 °C et s\'arrête à 20,3 °C.}}');
							thermostatbeNumber('ac_hysteresis', '{{Hystérésis clim}}', '± °C', '0,5');
							?>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-star"></i> {{Préréglages}}</legend>
							<?php foreach (array('comfort' => '{{Confort}}', 'eco' => '{{Éco}}', 'away' => '{{Absent}}') as $preset => $label) { ?>
								<div class="form-group">
									<label class="col-sm-5 control-label"><?php echo $label; ?></label>
									<div class="col-sm-3">
										<div class="input-group">
											<span class="input-group-addon roundedLeft"><i class="fas fa-fire"></i></span>
											<input type="text" class="eqLogicAttr form-control roundedRight" data-l1key="configuration" data-l2key="preset_<?php echo $preset; ?>_heat" placeholder="<?php echo thermostatbe::PRESETS[$preset]['heat']; ?>">
										</div>
									</div>
									<div class="col-sm-3">
										<div class="input-group">
											<span class="input-group-addon roundedLeft"><i class="fas fa-snowflake"></i></span>
											<input type="text" class="eqLogicAttr form-control roundedRight" data-l1key="configuration" data-l2key="preset_<?php echo $preset; ?>_cool" placeholder="<?php echo thermostatbe::PRESETS[$preset]['cool']; ?>">
										</div>
									</div>
								</div>
							<?php } ?>
							<div class="form-group">
								<div class="col-sm-11 col-sm-offset-1">
									<span class="help-block" style="margin:0;">{{La commande « Choisir le préréglage » applique les deux consignes d'un coup : un scénario ou l'agenda s'en sert pour la nuit ou les absences.}}</span>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-calendar-alt"></i> {{Saison (mode Auto)}}</legend>
							<?php
							thermostatbeNumber('season_heat_below', '{{Saison de chauffe sous}}', '°C', '15',
								'{{Moyenne extérieure lissée sur environ un jour. Entre les deux seuils, la saison en cours est gardée.}}');
							thermostatbeNumber('season_cool_above', '{{Saison de froid au-dessus de}}', '°C', '20');
							thermostatbeNumber('changeover_lock', '{{Verrou chaud ↔ froid}}', 'h', '12',
								'{{Après avoir chauffé, pas de froid pendant ce délai, et inversement. C\'est ce qui empêche la clim de refroidir la chaleur que la chaudière vient de produire. Ne s\'applique pas quand le mode est imposé.}}');
							thermostatbeNumber('cool_min_outdoor', '{{Froid seulement si dehors ≥}}', '°C', '',
								'{{Facultatif. S\'il fait plus frais dehors, ouvrir les fenêtres suffit.}}');
							?>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-euro-sign"></i> {{Chaudière ou clim (source Auto)}}</legend>
							<?php
							thermostatbeNumber('gas_price', '{{Prix du gaz}}', '€/kWh', '0,10',
								'{{Un nombre, ou une commande info (#[…]#) si le prix est tenu à jour ailleurs.}}');
							thermostatbeNumber('elec_price', '{{Prix de l\'électricité}}', '€/kWh', '0,30',
								'{{Un nombre, ou une commande info pour un tarif dynamique.}}');
							thermostatbeNumber('boiler_efficiency', '{{Rendement de la chaudière}}', '%', '90');
							thermostatbeNumber('cop_at_7', '{{COP de la clim à 7 °C}}', '', '3,5',
								'{{Sur la fiche technique de la clim. Le thermostat trace une droite entre les deux points.}}');
							thermostatbeNumber('cop_at_minus7', '{{COP de la clim à −7 °C}}', '', '2');
							thermostatbeNumber('cost_margin', '{{Marge avant de changer}}', '%', '10',
								'{{On ne quitte l\'appareil en cours que si l\'autre est moins cher d\'au moins cette marge.}}');
							thermostatbeNumber('auto_ac_above', '{{Sans prix : clim au-dessus de}}', '°C', '5',
								'{{Si un des deux prix manque, la règle est simplement la température extérieure.}}');
							thermostatbeNumber('ac_heat_min_outdoor', '{{Jamais la clim sous}}', '°C', '−5',
								'{{En Auto, sous cette température, toujours la chaudière.}}');
							?>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-solar-panel"></i> {{Priorité au surplus solaire (source Auto)}}</legend>
							<div class="form-group">
								<label class="col-sm-5 control-label">{{Activer}}</label>
								<div class="col-sm-6">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="solar_enable">
									<span class="help-block" style="margin:0;">{{Désactivé par défaut. Quand les panneaux exportent, la clim chauffe au lieu de la chaudière : de l'électricité revendue presque rien chauffe la maison.}}</span>
								</div>
							</div>
							<?php
							thermostatbeCmdField('solar_cmd', '{{Puissance au compteur}}', 'info', false,
								'{{En watts, positive quand la maison importe du réseau, négative quand elle exporte — la puissance active du compteur P1 HomeWizard, par exemple.}}');
							?>
							<div class="form-group">
								<label class="col-sm-5 control-label">{{Positive = export}}</label>
								<div class="col-sm-6">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="solar_invert">
									<span class="help-block" style="margin:0;">{{Cochez si votre mesure est positive quand la maison exporte.}}</span>
								</div>
							</div>
							<?php
							thermostatbeNumber('solar_export_min', '{{Démarrer la clim à partir de}}', '{{W exportés}}', '800');
							thermostatbeNumber('solar_import_max', '{{La garder tant qu\'on importe moins de}}', 'W', '300',
								'{{La clim en marche consomme le surplus qui l\'a fait démarrer : ce second seuil évite qu\'elle s\'arrête à la minute suivante.}}');
							?>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-shield-alt"></i> {{Protections}}</legend>
							<?php
							thermostatbeNumber('boiler_min_on', '{{Chaudière : marche minimale}}', 'min', '5');
							thermostatbeNumber('boiler_min_off', '{{Chaudière : arrêt minimal}}', 'min', '5');
							thermostatbeNumber('ac_min_on', '{{Clim : marche minimale}}', 'min', '15');
							thermostatbeNumber('ac_min_off', '{{Clim : arrêt minimal}}', 'min', '10');
							thermostatbeNumber('ac_mode_delay', '{{Clim : arrêt avant changement de mode}}', 'min', '10');
							thermostatbeNumber('ac_heat_offset', '{{Clim : décalage de consigne en chaud}}', '°C', '1',
								'{{Ajouté à la consigne envoyée à la clim. Sa sonde est en hauteur, dans l\'air chaud : sans décalage, elle s\'arrête avant que la pièce soit à température.}}');
							thermostatbeNumber('ac_cool_offset', '{{Clim : décalage de consigne en froid}}', '°C', '−1');
							thermostatbeNumber('window_delay', '{{Pause après fenêtre ouverte}}', 's', '60');
							?>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- ========================================== PROGRAMMATION ========================================== -->
			<div role="tabpanel" class="tab-pane" id="scheduletab">
				<br>
				<div class="alert alert-info" style="margin:5px 5px 10px 5px;">
					{{Chaque plage change de préréglage à son heure, les jours cochés : Confort à 6 h 30 en semaine, Éco à 22 h 30 tous les jours… Entre deux plages, ce que vous réglez à la main tient jusqu'au changement suivant. Une plage manquée de plus de 15 minutes (Jeedom arrêté) n'est pas rattrapée. Les consignes des préréglages se règlent dans l'onglet « Réglages ».}}
				</div>
				<div style="margin:0 0 10px 5px;">
					<label class="checkbox-inline"><input type="checkbox" id="cb_thermostatbeSchedule"> {{Programmation active}}</label>
					<span class="text-muted" style="margin-left:10px;">{{appliqué immédiatement — aussi par les commandes « Activer / Suspendre la programmation »}}</span>
					<span id="span_thermostatbeNextSchedule" class="label label-info" style="margin-left:10px;"></span>
				</div>
				<a class="btn btn-default btn-sm" id="bt_thermostatbeAddSlot" style="margin:0 0 10px 5px;"><i class="fas fa-plus-circle"></i> {{Ajouter une plage}}</a>
				<table class="table table-bordered table-condensed" id="table_thermostatbeSchedule">
					<thead>
						<tr>
							<th style="width:70px;">{{Active}}</th>
							<th style="width:110px;">{{Heure}}</th>
							<th>{{Jours}}</th>
							<th style="width:22%;">{{Préréglage}}</th>
							<th style="width:40px;"></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
				<template id="tpl_thermostatbeSlot">
					<tr class="tbSlot">
						<td><input type="checkbox" class="tbSlotAttr" data-key="enable" checked></td>
						<td><input type="time" class="form-control input-sm tbSlotAttr" data-key="time" value="06:30"></td>
						<td>
							<?php foreach (array(1 => '{{Lun}}', 2 => '{{Mar}}', 3 => '{{Mer}}', 4 => '{{Jeu}}', 5 => '{{Ven}}', 6 => '{{Sam}}', 7 => '{{Dim}}') as $day => $label) { ?>
								<label class="checkbox-inline" style="margin-right:6px;"><input type="checkbox" class="tbSlotDay" data-day="<?php echo $day; ?>" checked> <?php echo $label; ?></label>
							<?php } ?>
						</td>
						<td>
							<select class="form-control input-sm tbSlotAttr" data-key="preset">
								<option value="comfort">{{Confort}}</option>
								<option value="eco">{{Éco}}</option>
								<option value="away">{{Absent}}</option>
							</select>
						</td>
						<td><a class="btn btn-danger btn-sm tbSlotRemove" title="{{Supprimer}}"><i class="fas fa-minus-circle"></i></a></td>
					</tr>
				</template>
			</div>

			<!-- ========================================== CONDITIONS ========================================== -->
			<div role="tabpanel" class="tab-pane" id="conditiontab">
				<br>
				<div class="alert alert-info" style="margin:5px 5px 10px 5px;">
					{{Une condition vraie change ce que fait le thermostat, le temps qu'elle reste vraie : « ne rien chauffer quand l'alarme est armée », « consignes Absent quand personne n'est à la maison », « jamais la clim en heures pleines ». L'expression s'écrit comme dans un scénario, par exemple}}
					<code>#[Maison][Alarme][Actif]# == 1</code>.
					{{Le thermostat réagit dès que la commande change. Le hors-gel reste toujours assuré, sauf avec « Tout arrêter ». Une expression que Jeedom ne sait pas calculer est ignorée, et le journal le signale.}}
				</div>
				<a class="btn btn-default btn-sm" id="bt_thermostatbeAddCondition" style="margin:0 0 10px 5px;"><i class="fas fa-plus-circle"></i> {{Ajouter une condition}}</a>
				<table class="table table-bordered table-condensed" id="table_thermostatbeConditions">
					<thead>
						<tr>
							<th style="width:70px;">{{Active}}</th>
							<th style="width:22%;">{{Nom}}</th>
							<th>{{Si cette expression est vraie}}</th>
							<th style="width:24%;">{{Alors}}</th>
							<th style="width:40px;"></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
				<!-- Le modèle d'une ligne : copié par le JS, jamais envoyé tel quel. -->
				<template id="tpl_thermostatbeCondition">
					<tr class="tbCondition">
						<td><input type="checkbox" class="tbCondAttr" data-key="enable" checked></td>
						<td><input type="text" class="form-control input-sm tbCondAttr" data-key="name" placeholder="{{Alarme armée}}"></td>
						<td>
							<div class="input-group">
								<input type="text" class="form-control input-sm roundedLeft tbCondAttr" data-key="expression" placeholder="#[Maison][Alarme][Actif]# == 1">
								<span class="input-group-btn">
									<a class="btn btn-default btn-sm roundedRight tbCondPick" title="{{Insérer une commande}}"><i class="fas fa-list-alt"></i></a>
								</span>
							</div>
						</td>
						<td>
							<select class="form-control input-sm tbCondAttr" data-key="effect">
								<?php foreach (thermostatbe::EFFECTS as $effect) { ?>
									<option value="<?php echo $effect; ?>"><?php echo thermostatbe::effectLabel($effect); ?></option>
								<?php } ?>
							</select>
						</td>
						<td><a class="btn btn-danger btn-sm tbCondRemove" title="{{Supprimer}}"><i class="fas fa-minus-circle"></i></a></td>
					</tr>
				</template>
			</div>

			<!-- ========================================== COMMANDES ========================================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<table id="table_cmd" class="table table-bordered table-condensed">
					<thead>
						<tr>
							<th>{{Nom}}</th>
							<th>{{Type}}</th>
							<th>{{Options}}</th>
							<th>{{Action}}</th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'thermostatbe', 'js', 'thermostatbe'); ?>
