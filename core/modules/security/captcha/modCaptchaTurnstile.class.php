<?php
/* Copyright (C) 2026 DPS Docs <soporte@dpsdocs.pro>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/modules/security/captcha/modCaptchaTurnstile.class.php
 * \ingroup sydturnstile
 * \brief   Manejador de captcha basado en Cloudflare Turnstile.
 *
 * El nucleo lo instancia desde core/tpl/login.tpl.php (pinta) y main.inc.php (valida).
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/security/captcha/modules_captcha.php';

class modCaptchaTurnstile extends ModeleCaptcha
{
	/** @var string */
	public $id;

	/** @var string */
	public $picto = 'fa-shield-alt';

	/** @var int */
	public $position = 20;

	const API_JS = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	const API_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * @param DoliDB    $db    Database handler
	 * @param Conf      $conf  Conf handler
	 * @param Translate $langs Lang handler
	 * @param User|null $user  User handler
	 */
	public function __construct($db, $conf, $langs, $user)
	{
		$this->id = strtolower(preg_replace('/^modCaptcha/i', '', get_class($this)));

		$this->db = $db;
		$this->conf = $conf;
		$this->langs = $langs;
		$this->user = $user;
	}

	/**
	 * Descripcion mostrada en la pagina de configuracion de seguridad
	 *
	 * @return string
	 */
	public function getDescription()
	{
		global $langs;
		$langs->load('sydturnstile@sydturnstile');

		$txt = $langs->trans('SydTurnstileCaptchaDescription');
		if (!$this->estaConfigurado()) {
			$txt .= ' <span class="warning">'.$langs->trans('SydTurnstileNotConfigured').'</span>';
		}

		return $txt;
	}

	/**
	 * Ejemplo para la pagina de configuracion
	 *
	 * @return string
	 */
	public function getExample()
	{
		global $langs;
		$langs->load('sydturnstile@sydturnstile');

		return '<span class="opacitymedium">'.$langs->trans('SydTurnstileExample').'</span>';
	}

	/**
	 * Titulo del campo de entrada
	 *
	 * @return string
	 */
	public function getFieldInputTitle()
	{
		global $langs;
		$langs->load('sydturnstile@sydturnstile');

		return $langs->trans('SydTurnstileFieldTitle');
	}

	/**
	 * HTML del widget, impreso por core/tpl/login.tpl.php
	 *
	 * @param  string $php_self URL del formulario
	 * @return string
	 */
	public function getCaptchaCodeForForm($php_self = '')
	{
		$sitekey = getDolGlobalString('SYDTURNSTILE_SITEKEY');

		// Sin clave configurada no se pinta nada: el acceso no se bloquea por un
		// modulo a medio configurar. validateCodeAfterLoginSubmit() hace lo mismo.
		if (empty($sitekey)) {
			return '<!-- SydTurnstile: sin SYDTURNSTILE_SITEKEY configurada -->'."\n";
		}

		// 'light' por defecto: con 'auto' el widget sale negro en cuanto el navegador
		// o el tema van en modo oscuro, y desentona con la pantalla de acceso.
		$theme = getDolGlobalString('SYDTURNSTILE_THEME', 'light');
		if (!in_array($theme, array('auto', 'light', 'dark'), true)) {
			$theme = 'auto';
		}

		$out = '<!-- SydTurnstile -->'."\n";
		$out .= '<div class="trinputlogin">'."\n";
		$out .= '<div class="tagtd tdinputlogin nowrap none valignmiddle">'."\n";
		$out .= '<div class="cf-turnstile" data-sitekey="'.dol_escape_htmltag($sitekey).'"';
		$out .= ' data-theme="'.dol_escape_htmltag($theme).'"';
		$out .= ' data-response-field-name="cf-turnstile-response"></div>'."\n";
		$out .= '</div>'."\n";
		$out .= '</div>'."\n";
		// defer y sin async: el script corre tras el parseo, con el div ya en el DOM,
		// que es cuando el auto-render de Cloudflare si se dispara.
		$out .= '<script src="'.self::API_JS.'" defer></script>'."\n";
		$out .= '<!-- fin SydTurnstile -->'."\n";

		return $out;
	}

	/**
	 * Valida el token contra Cloudflare. Lo llama main.inc.php antes de comprobar la contrasena.
	 *
	 * @return int 0 si KO, >0 si OK
	 */
	public function validateCodeAfterLoginSubmit()
	{
		if (!$this->estaConfigurado()) {
			// Modulo a medio configurar: no es motivo para dejar a nadie fuera.
			dol_syslog('modCaptchaTurnstile: sin claves configuradas, se deja pasar', LOG_WARNING);
			return 1;
		}

		$token = $this->leerToken();
		if ($token === '') {
			dol_syslog('modCaptchaTurnstile: sin token en el envio (ip='.getUserRemoteIP().')', LOG_WARNING);
			return 0;
		}

		$respuesta = $this->verificarEnCloudflare($token);

		if ($respuesta === null) {
			// API inalcanzable. Con fail-open activo no se tumba el acceso al ERP por
			// una caida de red de Cloudflare; el token sigue siendo obligatorio.
			if (getDolGlobalInt('SYDTURNSTILE_FAILOPEN', 1)) {
				dol_syslog('modCaptchaTurnstile: API inalcanzable, se deja pasar (fail-open)', LOG_WARNING);
				return 1;
			}
			dol_syslog('modCaptchaTurnstile: API inalcanzable, se rechaza (fail-closed)', LOG_ERR);
			return 0;
		}

		if (!empty($respuesta['success'])) {
			return 1;
		}

		$codigos = isset($respuesta['error-codes']) ? implode(',', (array) $respuesta['error-codes']) : 'sin-codigo';
		dol_syslog('modCaptchaTurnstile: token rechazado ['.$codigos.'] ip='.getUserRemoteIP(), LOG_WARNING);

		return 0;
	}

	/**
	 * @return bool true si hay sitekey y secret
	 */
	private function estaConfigurado()
	{
		return getDolGlobalString('SYDTURNSTILE_SITEKEY') !== '' && getDolGlobalString('SYDTURNSTILE_SECRET') !== '';
	}

	/**
	 * Lee el token del POST.
	 *
	 * No se usa GETPOST: el nombre del campo lleva guiones y los filtros tipo
	 * alphanohtml pueden mutilar el token. Se valida con una lista blanca estricta.
	 *
	 * @return string Token, o cadena vacia
	 */
	private function leerToken()
	{
		if (empty($_POST['cf-turnstile-response'])) {
			return '';
		}

		$token = (string) $_POST['cf-turnstile-response'];
		if (!preg_match('/^[A-Za-z0-9._~\-]{10,4096}$/', $token)) {
			dol_syslog('modCaptchaTurnstile: token con formato invalido', LOG_WARNING);
			return '';
		}

		return $token;
	}

	/**
	 * @param  string     $token Token a verificar
	 * @return array|null Respuesta decodificada, o null si no se pudo consultar
	 */
	private function verificarEnCloudflare($token)
	{
		include_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		$datos = array(
			'secret'   => getDolGlobalString('SYDTURNSTILE_SECRET'),
			'response' => $token,
		);
		$ip = getUserRemoteIP();
		if (!empty($ip)) {
			$datos['remoteip'] = $ip;
		}

		// Timeouts explicitos: sin ellos un Cloudflare lento dejaria el acceso colgado.
		$r = getURLContent(self::API_VERIFY, 'POST', http_build_query($datos), 1, array(), array('http', 'https'), 0, -1, 5, 8);

		if (empty($r['content']) || (!empty($r['curl_error_no']) && $r['curl_error_no'] !== 0)) {
			return null;
		}

		$d = json_decode($r['content'], true);

		return is_array($d) ? $d : null;
	}
}
