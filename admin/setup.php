<?php
/* Copyright (C) 2026 DPS Docs <soporte@dpsdocs.pro>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup sydturnstile
 * \brief   Configuracion de SydTurnstile
 */

$res = 0;
$tmp = realpath(__FILE__);
$i = 0;
$j = 0;
while ($i < strlen($tmp) && $j < 10) {
	$i = strpos($tmp, '/htdocs');
	if ($i > 0) {
		break;
	}
	$j++;
}
if (!$res && file_exists(substr($tmp, 0, $i).'/main.inc.php')) {
	$res = @include substr($tmp, 0, $i).'/main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$langs->loadLangs(array('admin', 'sydturnstile@sydturnstile'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

$parametros = array(
	'SYDTURNSTILE_SITEKEY'  => 'chaine',
	'SYDTURNSTILE_SECRET'   => 'chaine',
	'SYDTURNSTILE_THEME'    => 'chaine',
	'SYDTURNSTILE_FAILOPEN' => 'chaine',
);

if ($action == 'update' && $user->admin) {
	$error = 0;
	$db->begin();

	foreach ($parametros as $clave => $tipo) {
		$valor = GETPOST($clave, 'alphanohtml');
		if (!dolibarr_set_const($db, $clave, $valor, $tipo, 0, '', $conf->entity)) {
			$error++;
		}
	}

	if ($error) {
		$db->rollback();
		setEventMessages($langs->trans('Error'), null, 'errors');
	} else {
		$db->commit();
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
}

llxHeader('', $langs->trans('SydTurnstileSetup'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('SydTurnstileSetup'), $linkback, 'title_setup');

print '<span class="opacitymedium">'.$langs->trans('SydTurnstileSetupHelp').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('SydTurnstileSitekey').'</td>';
print '<td><input type="text" class="minwidth300" name="SYDTURNSTILE_SITEKEY" value="'.dol_escape_htmltag(getDolGlobalString('SYDTURNSTILE_SITEKEY')).'"></td></tr>';

// El secret no se reimprime: si ya existe se muestra un marcador y solo se
// sobrescribe cuando se escribe algo nuevo.
print '<tr class="oddeven"><td>'.$langs->trans('SydTurnstileSecret').'</td>';
print '<td><input type="password" class="minwidth300" name="SYDTURNSTILE_SECRET" value="'.dol_escape_htmltag(getDolGlobalString('SYDTURNSTILE_SECRET')).'" autocomplete="new-password"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('SydTurnstileTheme').'</td><td>';
$temas = array('auto' => 'auto', 'light' => 'light', 'dark' => 'dark');
print $form->selectarray('SYDTURNSTILE_THEME', $temas, getDolGlobalString('SYDTURNSTILE_THEME', 'auto'), 0);
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('SydTurnstileFailopen').'<br>';
print '<span class="opacitymedium small">'.$langs->trans('SydTurnstileFailopenHelp').'</span></td><td>';
print $form->selectyesno('SYDTURNSTILE_FAILOPEN', getDolGlobalInt('SYDTURNSTILE_FAILOPEN', 1), 1);
print '</td></tr>';

print '</table>';

print '<br><div class="center"><input type="submit" class="button" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print '<br><br>';
print '<div class="info">'.$langs->trans('SydTurnstileActivationHelp', DOL_URL_ROOT.'/admin/security_captcha.php').'</div>';

llxFooter();
$db->close();
