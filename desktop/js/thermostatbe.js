/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* ================================================================== OUTILS */

function thermostatbeMarkModified() {
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

function thermostatbeCurrentId() {
  var field = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (field && field.value !== '') ? field.value : null
}

function thermostatbeText(_tag, _text, _className) {
  var element = document.createElement(_tag)
  element.textContent = _text
  if (_className) { element.className = _className }
  return element
}

/* ================================================================ EN CE MOMENT */

/* La couleur de l'étiquette d'état : ce qui tourne, ce qui attend, ce qui est
   coupé pour une raison qu'il faut regarder. */
var thermostatbeStatusClass = {
  heating: 'label-danger',
  cooling: 'label-info',
  waiting: 'label-warning',
  blocked: 'label-warning',
  window: 'label-warning',
  safety: 'label-danger',
  off: 'label-default',
  idle: 'label-success'
}

/* Construit en DOM, sans HTML concaténé : les noms de sondes viennent des
   équipements de l'utilisateur, et un nom contenant une balise ne doit pas
   s'exécuter dans la page. */
function thermostatbeShowStatus(_status) {
  var root = document.getElementById('div_thermostatbeStatus')
  if (!root) { return }
  root.innerHTML = ''
  if (_status && _status.runtime) {
    thermostatbeFillRuntime(_status.runtime)
  }
  thermostatbeShowJournal(_status ? _status.journal : null)
  if (!_status || !_status.target) {
    root.appendChild(thermostatbeText('span', '{{Pas encore de décision : enregistrez le thermostat, ou cliquez sur « Évaluer maintenant ».}}'))
    return
  }

  var head = document.createElement('div')
  var label = thermostatbeText('span', _status.target, 'label ' + (thermostatbeStatusClass[_status.status] || 'label-default'))
  label.style.fontSize = '14px'
  head.appendChild(label)
  head.appendChild(thermostatbeText('span', '  {{à}} ' + _status.at, 'text-muted'))
  root.appendChild(head)

  var reason = thermostatbeText('p', _status.reason)
  reason.style.margin = '8px 0'
  root.appendChild(reason)

  if (_status.conditions && _status.conditions.length > 0) {
    var active = document.createElement('div')
    active.className = 'alert alert-warning'
    active.style.padding = '6px 10px'
    active.style.margin = '0 0 8px 0'
    active.appendChild(thermostatbeText('b', '{{Conditions actives}} : '))
    active.appendChild(thermostatbeText('span', _status.conditions.map(function (condition) {
      return condition.name + ' → ' + condition.effect
    }).join(' · ')))
    root.appendChild(active)
  }

  var rows = [
    ['{{Intérieur}}', _status.indoor],
    ['{{Consigne}}', _status.setpoint],
    ['{{Extérieur}}', _status.outdoor],
    ['{{Moyenne extérieure}}', _status.outdoor_avg],
    ['{{Saison}}', _status.season]
  ]
  if (_status.ac_setpoint && _status.ac_setpoint !== '—') {
    rows.push(['{{Consigne envoyée à la clim}}', _status.ac_setpoint])
  }
  if (_status.source_why) {
    rows.push(['{{Choix de l\'appareil}}', _status.source_why])
  }
  if (_status.costs) {
    rows.push(['{{kWh de chaleur}}', '{{chaudière}} ' + _status.costs.boiler.toFixed(3) + ' € · {{clim}} '
      + _status.costs.ac.toFixed(3) + ' € (COP ' + _status.costs.cop + ')'])
  }
  var table = document.createElement('table')
  table.className = 'table table-condensed'
  table.style.marginBottom = '6px'
  rows.forEach(function (row) {
    var tr = document.createElement('tr')
    tr.appendChild(thermostatbeText('th', row[0]))
    tr.appendChild(thermostatbeText('td', row[1]))
    table.appendChild(tr)
  })
  root.appendChild(table)

  if (_status.sensors && _status.sensors.length > 0) {
    root.appendChild(thermostatbeText('b', '{{Sondes intérieures}}'))
    var list = document.createElement('ul')
    list.style.margin = '4px 0 0 0'
    _status.sensors.forEach(function (sensor) {
      var text = sensor.name + ' : ' + sensor.value
      if (sensor.stale) {
        text = sensor.name + ' : {{muette depuis}} ' + sensor.age + ' — {{écartée}}'
      } else if (sensor.age) {
        text += ' ({{il y a}} ' + sensor.age + ')'
      }
      list.appendChild(thermostatbeText('li', text, sensor.stale ? 'text-warning' : ''))
    })
    root.appendChild(list)
  }
}

/* ============================================================== MARCHE */

/* Vrai pendant qu'on repose des valeurs à l'écran : une valeur posée par le
   script émet « change » comme une saisie, et renverrait au serveur ce qu'il
   vient de nous dire. */
var thermostatbeRendering = false

function thermostatbeFillRuntime(_runtime) {
  thermostatbeRendering = true
  try {
    var schedule = document.getElementById('cb_thermostatbeSchedule')
    if (schedule) { schedule.checked = (_runtime.schedule == 1) }
    var next = document.getElementById('span_thermostatbeNextSchedule')
    if (next) { next.textContent = _runtime.next_schedule ? '{{Prochain}} : ' + _runtime.next_schedule : '' }
    var boost = document.getElementById('bt_thermostatbeBoost')
    if (boost) {
      boost.classList.toggle('btn-danger', _runtime.boost_until !== '')
      boost.querySelector('span').textContent = (_runtime.boost_until !== '') ? '{{Boost jusqu\'à}} ' + _runtime.boost_until + ' — {{arrêter}}' : '{{Boost}}'
      boost.setAttribute('data-on', (_runtime.boost_until !== '') ? '1' : '0')
    }
    document.querySelectorAll('.tbRuntime').forEach(function (field) {
      var key = field.getAttribute('data-key')
      if (!isset(_runtime[key])) { return }
      var value = _runtime[key]
      if (key == 'heat_setpoint' || key == 'cool_setpoint') {
        value = String(value).replace('.', ',')
      }
      field.value = value
    })
  } finally {
    thermostatbeRendering = false
  }
}

/* Les champs de marche n'ont de sens que pour un thermostat enregistré : ils
   parlent au serveur par son identifiant. */
function thermostatbeEnableRuntime(_enabled) {
  document.querySelectorAll('.tbRuntime').forEach(function (field) {
    field.disabled = !_enabled
  })
  document.querySelectorAll('.tbRuntimeNew').forEach(function (block) {
    block.style.display = _enabled ? 'none' : ''
  })
}

function thermostatbeSetRuntime(_field) {
  var id = thermostatbeCurrentId()
  if (id === null) { return }
  thermostatbeRequest({ action: 'setRuntime', id: id, key: _field.getAttribute('data-key'), value: _field.value },
    function (result) {
      thermostatbeShowStatus(result)
      jeedomUtils.showAlert({ message: '{{Appliqué}}', level: 'success', timeOut: 1500 })
    },
    function () {
      /* Refusé : on remet à l'écran ce qui s'applique vraiment. */
      thermostatbeLoadStatus(false)
    })
}

function thermostatbeRequest(_data, _success, _failure) {
  var id = _data.id
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/thermostatbe/core/ajax/thermostatbe.ajax.php',
    data: _data,
    dataType: 'json',
    noDisplayError: true,
    error: function (request, status, error) {
      domUtils.handleAjaxError(request, status, error)
      if (_failure) { _failure() }
    },
    success: function (data) {
      if (data.state != 'ok') {
        jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        if (_failure) { _failure() }
        return
      }
      /* La page a pu changer de thermostat pendant l'aller-retour. */
      if (thermostatbeCurrentId() !== id) { return }
      _success(data.result)
    }
  })
}

/* Le journal des décisions, le plus récent en haut. Les alertes en couleur :
   c'est ce qu'on vient chercher quand quelque chose a surpris. */
var thermostatbeJournalClass = { alert: 'text-danger', user: 'text-primary', state: '', info: 'text-muted' }

function thermostatbeShowJournal(_entries) {
  var root = document.getElementById('div_thermostatbeJournal')
  if (!root) { return }
  root.innerHTML = ''
  if (!_entries || _entries.length === 0) {
    root.appendChild(thermostatbeText('span', '{{Rien pour l\'instant.}}', 'text-muted'))
    return
  }
  var table = document.createElement('table')
  table.className = 'table table-condensed'
  table.style.margin = '0'
  _entries.forEach(function (entry) {
    var tr = document.createElement('tr')
    var when = thermostatbeText('td', entry.when, 'text-muted')
    when.style.whiteSpace = 'nowrap'
    when.style.width = '1%'
    tr.appendChild(when)
    tr.appendChild(thermostatbeText('td', entry.text, thermostatbeJournalClass[entry.kind] || ''))
    table.appendChild(tr)
  })
  root.appendChild(table)
}

function thermostatbeLoadStatus(_refresh) {
  var id = thermostatbeCurrentId()
  if (id === null) {
    thermostatbeShowStatus(null)
    return
  }
  thermostatbeRequest({ action: 'status', id: id, refresh: _refresh ? 1 : 0 }, function (result) {
    thermostatbeShowStatus(result)
  })
}

/* ========================================================= PROGRAMMATION */

function thermostatbeAddSlot(_slot) {
  var template = document.getElementById('tpl_thermostatbeSlot')
  var body = document.querySelector('#table_thermostatbeSchedule tbody')
  if (!template || !body) { return }
  var row = template.content.firstElementChild.cloneNode(true)
  if (_slot) {
    row.querySelector('.tbSlotAttr[data-key="enable"]').checked = (_slot.enable != 0)
    row.querySelector('.tbSlotAttr[data-key="time"]').value = _slot.time || '06:30'
    row.querySelector('.tbSlotAttr[data-key="preset"]').value = _slot.preset || 'comfort'
    var days = Array.isArray(_slot.days) ? _slot.days.map(Number) : []
    row.querySelectorAll('.tbSlotDay').forEach(function (box) {
      box.checked = days.indexOf(Number(box.getAttribute('data-day'))) !== -1
    })
  }
  body.appendChild(row)
}

function thermostatbeReadSlots() {
  var slots = []
  document.querySelectorAll('#table_thermostatbeSchedule .tbSlot').forEach(function (row) {
    var days = []
    row.querySelectorAll('.tbSlotDay').forEach(function (box) {
      if (box.checked) { days.push(Number(box.getAttribute('data-day'))) }
    })
    slots.push({
      enable: row.querySelector('.tbSlotAttr[data-key="enable"]').checked ? 1 : 0,
      time: row.querySelector('.tbSlotAttr[data-key="time"]').value,
      days: days,
      preset: row.querySelector('.tbSlotAttr[data-key="preset"]').value
    })
  })
  return slots
}

/* ============================================================ CONDITIONS */

function thermostatbeAddCondition(_condition) {
  var template = document.getElementById('tpl_thermostatbeCondition')
  var body = document.querySelector('#table_thermostatbeConditions tbody')
  if (!template || !body) { return }
  var row = template.content.firstElementChild.cloneNode(true)
  var condition = _condition || {}
  row.querySelectorAll('.tbCondAttr').forEach(function (field) {
    var key = field.getAttribute('data-key')
    if (field.type == 'checkbox') {
      field.checked = !isset(condition.enable) || condition.enable == 1
    } else if (isset(condition[key])) {
      field.value = condition[key]
    }
  })
  body.appendChild(row)
}

function thermostatbeReadConditions() {
  var conditions = []
  document.querySelectorAll('#table_thermostatbeConditions .tbCondition').forEach(function (row) {
    var condition = {}
    row.querySelectorAll('.tbCondAttr').forEach(function (field) {
      condition[field.getAttribute('data-key')] = (field.type == 'checkbox') ? (field.checked ? 1 : 0) : field.value
    })
    if (condition.expression && condition.expression.trim() !== '') {
      conditions.push(condition)
    }
  })
  return conditions
}

/* ================================================== CYCLE DE VIE DE LA PAGE */

function printEqLogic(_eqLogic) {
  var configuration = (isset(_eqLogic) && isset(_eqLogic.configuration)) ? _eqLogic.configuration : {}
  var body = document.querySelector('#table_thermostatbeConditions tbody')
  if (body) { body.innerHTML = '' }
  var conditions = Array.isArray(configuration.conditions) ? configuration.conditions : []
  conditions.forEach(function (condition) { thermostatbeAddCondition(condition) })
  var slotBody = document.querySelector('#table_thermostatbeSchedule tbody')
  if (slotBody) { slotBody.innerHTML = '' }
  var slots = Array.isArray(configuration.schedule) ? configuration.schedule : []
  slots.forEach(function (slot) { thermostatbeAddSlot(slot) })

  thermostatbeShowStatus(null)
  var saved = isset(_eqLogic.id) && _eqLogic.id != ''
  thermostatbeEnableRuntime(saved)
  if (saved) {
    thermostatbeLoadStatus(false)
  }
}

/* Appelée par plugin.template.js avant l'enregistrement : les conditions sont
   une liste, que data-lXkey ne sait pas ramasser. */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }
  _eqLogic.configuration.conditions = thermostatbeReadConditions()
  _eqLogic.configuration.schedule = thermostatbeReadSlots()
  return _eqLogic
}

/* ================================================================ COMMANDES */

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  /* Le champ caché « id » n'est pas décoratif : sans lui, chaque sauvegarde
     détruit et recrée les commandes — historique perdu, scénarios cassés. */
  var tr = '<td>'
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (_cmd.type == 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'

  /* Une ligne créée en DOM : insertAdjacentHTML sur la table génère un <tbody>
     par insertion. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* ================================================================ ÉCOUTEURS */

/* Les pages sont chargées en AJAX : les écouteurs sont posés sur le conteneur
   de page, qui est remplacé à chaque navigation et les emporte avec lui. */
var thermostatbeContainer = document.getElementById('div_pageContainer') || document.body

thermostatbeContainer.addEventListener('click', function (event) {
  var target

  /* Choisir une commande : un champ simple est remplacé, un champ multiple
     (sondes, fenêtres) reçoit la commande en plus des autres. */
  if (target = event.target.closest('.tbPickCmd')) {
    var key = target.getAttribute('data-key')
    var multi = target.getAttribute('data-multi') == '1'
    var field = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="' + key + '"]')
    jeedom.cmd.getSelectModal({ cmd: { type: target.getAttribute('data-type') } }, function (result) {
      if (!result || !result.human) { return }
      if (multi && field.value.trim() !== '') {
        if (field.value.indexOf(result.human) === -1) {
          field.value = field.value.trim() + ' ' + result.human
        }
      } else {
        field.value = result.human
      }
      thermostatbeMarkModified()
    })
    return
  }

  if (target = event.target.closest('.tbClearCmd')) {
    var cleared = document.querySelector('.eqLogicAttr[data-l1key="configuration"][data-l2key="' + target.getAttribute('data-key') + '"]')
    if (cleared && cleared.value !== '') {
      cleared.value = ''
      thermostatbeMarkModified()
    }
    return
  }

  if (event.target.closest('#bt_thermostatbeRefresh')) {
    thermostatbeLoadStatus(true)
    return
  }

  if (event.target.closest('#bt_thermostatbeAddSlot')) {
    thermostatbeAddSlot(null)
    thermostatbeMarkModified()
    return
  }

  if (target = event.target.closest('.tbSlotRemove')) {
    target.closest('.tbSlot').remove()
    thermostatbeMarkModified()
    return
  }

  if (target = event.target.closest('.tbTest')) {
    var testId = thermostatbeCurrentId()
    if (testId === null) { return }
    thermostatbeRequest({ action: 'testDevice', id: testId, device: target.getAttribute('data-device'), do: target.getAttribute('data-do') },
      function (result) {
        jeedomUtils.showAlert({ message: '{{Ordre envoyé. Le thermostat reprend la main à}} ' + result.until + '.', level: 'success', timeOut: 5000 })
      })
    return
  }

  if (target = event.target.closest('#bt_thermostatbeBoost')) {
    var boostId = thermostatbeCurrentId()
    if (boostId === null) { return }
    thermostatbeRequest({ action: 'boost', id: boostId, on: target.getAttribute('data-on') == '1' ? 0 : 1 }, function (result) {
      thermostatbeShowStatus(result)
    })
    return
  }

  if (event.target.closest('#bt_thermostatbeAddCondition')) {
    thermostatbeAddCondition(null)
    thermostatbeMarkModified()
    return
  }

  if (target = event.target.closest('.tbCondRemove')) {
    target.closest('.tbCondition').remove()
    thermostatbeMarkModified()
    return
  }

  /* Une commande insérée à la suite de l'expression : une condition en cite
     souvent plusieurs, « #[…][Alarme]# == 1 && #[…][Présence]# == 0 ». */
  if (target = event.target.closest('.tbCondPick')) {
    var expression = target.closest('.tbCondition').querySelector('.tbCondAttr[data-key="expression"]')
    jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (result) {
      if (!result || !result.human) { return }
      expression.value = (expression.value.trim() === '') ? result.human : expression.value.trim() + ' ' + result.human
      thermostatbeMarkModified()
    })
    return
  }
})

thermostatbeContainer.addEventListener('change', function (event) {
  var field
  if (field = event.target.closest('.tbRuntime')) {
    if (!thermostatbeRendering) { thermostatbeSetRuntime(field) }
    return
  }
  if (event.target.id == 'cb_thermostatbeSchedule') {
    if (thermostatbeRendering) { return }
    var scheduleId = thermostatbeCurrentId()
    if (scheduleId === null) { return }
    thermostatbeRequest({ action: 'schedule', id: scheduleId, on: event.target.checked ? 1 : 0 }, function (result) {
      thermostatbeShowStatus(result)
    }, function () { thermostatbeLoadStatus(false) })
    return
  }
  /* Les champs des conditions et des plages ne sont pas des .eqLogicAttr :
     le coeur ne les voit pas changer, et quitterait la page sans prévenir. */
  if (event.target.closest('.tbCondAttr') || event.target.closest('.tbSlotAttr') || event.target.closest('.tbSlotDay')) {
    thermostatbeMarkModified()
  }
})
