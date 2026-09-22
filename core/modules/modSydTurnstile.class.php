<?php
/* Copyright (C) 2026 DPS Docs <soporte@dpsdocs.pro>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/modules/modSydTurnstile.class.php
 * \ingroup sydturnstile
 * \brief   Descriptor del modulo SydTurnstile: aporta un manejador de captcha
 *          basado en Cloudflare Turnstile.
 *
 * El nucleo de Dolibarr resuelve los manejadores de captcha recorriendo
 * $conf->modules_parts['captcha'] (ver main.inc.php y core/tpl/login.tpl.php).
 * Declarando module_parts['captcha'] = 1, conf.class.php registra por si solo la
 * ruta /sydturnstile/core/modules/security/captcha/ — no hace falta tocar el core.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modSydTurnstile extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Numero 149045: tomado del registro DPSdocs/module-registry (siguiente libre al 17-sep-2026).
		$this->numero = 149045;
		$this->rights_class = 'sydturnstile';
		$this->family = 'DPS';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Cloudflare Turnstile como captcha de Dolibarr';
		$this->descriptionlong = 'Aporta un manejador de captcha basado en Cloudflare Turnstile. '
			.'Cubre el acceso, la recuperacion de contrasena y los formularios publicos, '
			.'porque todos leen la misma constante MAIN_SECURITY_ENABLECAPTCHA_HANDLER.';
		$this->editor_name = 'DPS Docs';
		$this->editor_url = 'https://www.dpsdocs.pro';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-shield-alt';

		// Esta es la pieza clave: registra el directorio de manejadores de captcha del modulo.
		$this->module_parts = array(
			'captcha' => 1,
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@sydturnstile');

		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('sydturnstile@sydturnstile');

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(20, 0);

		// Constantes propias. No se fija ninguna clave por defecto a proposito:
		// el manejador queda inerte hasta que se configuren en la pagina del modulo.
		$this->const = array(
			array('SYDTURNSTILE_FAILOPEN', 'chaine', '1', 'Dejar pasar si la API de Cloudflare no responde', 0, 'current', 1),
			array('SYDTURNSTILE_THEME', 'chaine', 'light', 'Tema del widget: light, dark o auto', 0, 'current', 1),
		);

		// Sin permisos de usuario: un captcha no expone funcionalidad por rol.
		$this->rights = array();

		$this->menu = array();
	}

	/**
	 * Activacion del modulo
	 *
	 * @param  string $options Opciones
	 * @return int             1 si OK, 0 si KO
	 */
	public function init($options = '')
	{
		// El modulo no crea tablas: no hay _load_tables que llamar.
		return $this->_init(array(), $options);
	}

	/**
	 * Desactivacion del modulo
	 *
	 * @param  string $options Opciones
	 * @return int             1 si OK, 0 si KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
