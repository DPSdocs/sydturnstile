<?php
/* Copyright (C) 2026 DPS Docs <soporte@dpsdocs.pro>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/actions_sydturnstile.class.php
 * \ingroup sydturnstile
 * \brief   Engancha Turnstile al login del WebPortal (public/webportal/), que no dispara el
 *          captcha generico del core (MAIN_SECURITY_ENABLECAPTCHA*). Reutiliza tal cual la
 *          clase modCaptchaTurnstile ya usada por el backoffice, sin duplicar su logica.
 */

dol_include_once('/sydturnstile/core/modules/security/captcha/modCaptchaTurnstile.class.php');

class ActionsSydTurnstile
{
	/** @var DoliDB */
	public $db;
	public $error = '';
	public $errors = array();
	public $results = array();
	public $resprints = '';

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook PrintPageView (contextos webportal/webportalpage). Pinta el widget solo en el
	 * login. Imprime con echo directo -- confirmado en codigo real que ni
	 * LoginController::display() ni header_login.tpl.php hacen "print $hookmanager->resPrint"
	 * para este hook (a diferencia de header.tpl.php, que si lo hace para webPortalHeader);
	 * asignar a $this->resprints aqui se perderia en silencio. Retorna 0 siempre: es aditivo,
	 * la plantilla nativa de login sigue cargando debajo (ver
	 * LoginController::display() en webportal/controllers/login.controller.class.php).
	 *
	 * @param  array<string,mixed> $parameters Parametros del hook (trae 'controller')
	 * @param  Context             $object     Instancia de Context del WebPortal
	 * @param  string              $action     Accion en curso
	 * @param  HookManager         $hookmanager Gestor de hooks
	 * @return int 0 siempre
	 */
	public function PrintPageView($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $conf, $langs, $user;

		if (empty($parameters['controller']) || $parameters['controller'] !== 'login') {
			return 0;
		}

		$captcha = new modCaptchaTurnstile($db, $conf, $langs, $user);
		echo '<div id="sydturnstile-webportal-wrap" style="display:none;">';
		echo $captcha->getCaptchaCodeForForm();
		echo '</div>';
		// Se imprime antes del contenedor visual de la tarjeta (fuera de .login-screen__content);
		// se reubica con JS dentro del formulario, justo antes del boton de envio -- el lugar
		// estandar de un captcha -- y se revela (el widget en si es invisible salvo que
		// Cloudflare pida un desafio visual, pero el wrapper arranca oculto para no dejar un
		// hueco en blanco mientras el script de Cloudflare carga).
		echo '<script>document.addEventListener("DOMContentLoaded", function() {
			var cap = document.getElementById("sydturnstile-webportal-wrap");
			var form = document.querySelector("form.login");
			var submit = document.querySelector(".login__submit");
			if (cap && form && submit) {
				cap.style.display = "";
				form.insertBefore(cap, submit);
			}
		});</script>';

		return 0;
	}

	/**
	 * Hook beforeLoginAuthentication (contexto login), disparado siempre por
	 * webportal.main.inc.php antes de resolver login/password contra SocieteAccount.
	 *
	 * Solo actua sobre un envio real del formulario tradicional (action_login=login) -- el
	 * login SSO (sydmsftgraph) entra por su propio callback, nunca pasa por este bloque.
	 * Retorna 0 en todo caso que no deba bloquear (incluido fail-open): un valor > 0 aqui
	 * reemplazaria TODO el flujo de login nativo, no es lo que se quiere. Solo retorna < 0
	 * para bloquear explicitamente un token invalido con fail-open desactivado.
	 *
	 * @param  array<string,mixed> $parameters Parametros del hook
	 * @param  Context             $object     Instancia de Context del WebPortal
	 * @param  string              $action     Accion en curso
	 * @param  HookManager         $hookmanager Gestor de hooks
	 * @return int 0 para continuar el flujo normal, < 0 para bloquear
	 */
	public function beforeLoginAuthentication($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $conf, $langs, $user;

		$actionlogin = GETPOST('action_login', 'alphanohtml');
		if ($actionlogin !== 'login') {
			return 0;
		}

		$captcha = new modCaptchaTurnstile($db, $conf, $langs, $user);
		$ok = $captcha->validateCodeAfterLoginSubmit();

		if ($ok > 0) {
			return 0;
		}

		// validateCodeAfterLoginSubmit() ya aplico fail-open/sin-configurar internamente
		// (devuelve 1 en esos casos) -- si llegamos aqui es un rechazo real.
		$langs->load('sydturnstile@sydturnstile');
		$object->setEventMessage($langs->trans('SydTurnstileVerificationFailed'), 'errors');

		return -1;
	}
}
