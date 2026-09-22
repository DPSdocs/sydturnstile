# Plan — Cloudflare Turnstile en el login del WebPortal (SydTurnstile)

Fecha: 22-sep-2026 · Instancia objetivo: **erp.dpsdocs.pro** (Dolibarr 23.0.4, prodsrv03)
Método: Desarrollo Agéntico Verificado (DAV) — investigar contra el código real, plan aprobado
antes de codear, piezas atómicas con checkpoint obligatorio sobre datos reales.

Parte del pedido más amplio "arreglar `public/webportal/` (skin + SSO + Turnstile)". El skin vive
en `~/dev/sydsupportbranding/PLAN-WEBPORTAL-SKIN.md` y el SSO en
`~/dev/sydmsftgraph/PLAN-O365.md` (épica E0.6) — **sin módulo nuevo**, los tres convergen sobre la
misma pantalla de login del webportal, cada uno por su propio mecanismo de extensión.

---

## 0. Regla dura

**Fail-open siempre.** Si Cloudflare no responde, si la clave está mal configurada, o si hay un
bug propio: el login del webportal debe seguir funcionando — nunca puede quedar alguien afuera del
portal por una falla de este módulo. Mismo criterio que ya rige `SYDTURNSTILE_FAILOPEN` en el resto
de usos de Turnstile en dpsdocs.

---

## 1. Estado real verificado (22-sep-2026)

Repo (`DPSdocs/sydturnstile`, rama `main`) y servidor coinciden byte a byte, `modCaptchaTurnstile`
incluido (`git log` confirma que está desde el commit inicial `bc5d16b`) — sin drift. (Una primera
pasada de verificación usó `find -maxdepth 3`, que no alcanza ese archivo por estar a profundidad
4, y sugirió erróneamente que faltaba en el repo; corregido tras comparar `git log` y md5 reales.)

### 🔴 Hallazgo, no bloqueante para este plan: colisión de número de módulo
`modSydTurnstile` usa `numero = 149045` (tomado, según su propio comentario, el 17-sep-2026 del
registro). El registro `DPSdocs/module-registry` en su estado actual (22-sep-2026) asigna **ese
mismo 149045 a `sydkanban`**, ya implementado en Stone DEV y demo.dpsdocs.pro, y marca 149046 como
el siguiente libre. Es decir, dos módulos reales reclaman hoy el mismo número. No se toca en este
plan (es un tema de todo el registro, no de esta funcionalidad) — se deja anotado para no perderlo;
lo resuelve quien atienda el registro completo, no esta épica.

### Mecanismo real ya construido y reutilizable, sin modificar
`custom/sydturnstile/core/modules/security/captcha/modCaptchaTurnstile.class.php` (leído completo)
ya tiene toda la lógica necesaria, agnóstica de dónde se use:
- `getCaptchaCodeForForm($php_self='')`: imprime `<div class="cf-turnstile" data-sitekey="..."
  data-theme="...">` + carga diferida de `https://challenges.cloudflare.com/turnstile/v0/api.js`.
- `validateCodeAfterLoginSubmit()`: lee `$_POST['cf-turnstile-response']`, valida el formato con
  regex estricta, hace POST a `https://challenges.cloudflare.com/turnstile/v0/siteverify` con
  timeouts 5s/8s, y respeta `SYDTURNSTILE_FAILOPEN`.
- Constantes ya configuradas en dpsdocs: `SYDTURNSTILE_SITEKEY`, `SYDTURNSTILE_SECRET` (cifrada),
  `SYDTURNSTILE_THEME=light`, `SYDTURNSTILE_FAILOPEN=1`. Mismo dominio (`erp.dpsdocs.pro`) que el
  resto de usos — no debería hacer falta un sitekey nuevo para el webportal, pero se confirma en
  el checkpoint (Cloudflare valida por dominio registrado en el sitekey, no por ruta).

### Lo que falta: el webportal no dispara el captcha genérico del core
El mecanismo `module_parts['captcha']=1` solo lo invoca el núcleo de Dolibarr vía
`MAIN_SECURITY_ENABLECAPTCHA*` — confirmado por grep que el webportal (`/webportal/`,
`/public/webportal/`) **no tiene ninguna línea de código de captcha hoy**. Hace falta enganche
nuevo, específico del webportal, que **use la clase ya existente sin duplicar su lógica**.

### Puntos de extensión reales del webportal (verificados en código, sesión de hoy)
- `Controller::__construct()` (`webportal/class/controller.class.php`) ya llama
  `$hookmanager->initHooks(array('webportalpage', 'webportal'))` para **toda** página del
  webportal, login incluido — mismo contexto que ya usa `sydsupportbranding`.
- `LoginController::display()` hace
  `$hookRes = $this->hookPrintPageView(); if (empty($hookRes)) { $this->loadTemplate('login'); }`
  — un hook `PrintPageView` que imprime contenido y retorna `0` se **suma** antes de la plantilla
  nativa, no la reemplaza (mismo patrón que usará el botón de Microsoft en sydmsftgraph E0.6.1).
- `webportal.main.inc.php` (~línea 144) dispara **siempre**, incluso sin
  `WEBPORTAL_LOGIN_BY_MODULE`, el hook `beforeLoginAuthentication` con contexto `login` — el mismo
  nombre de contexto que ya usa `sydmsftgraph` para el backoffice, así que declararlo aquí no
  colisiona (cada handler se registra por módulo, no por nombre de contexto global) — es el punto
  exacto donde validar la respuesta de Turnstile **antes** de que se intente
  `getThirdPartyAccountFromLogin()`.

---

## 2. Épicas

### E1 — Declarar los hooks del webportal
- **Construir**: en `modSydTurnstile`, sumar `'hooks' => array('webportal', 'webportalpage',
  'login')` a `module_parts` (sin tocar `'captcha' => 1`, que sigue siendo el mecanismo del
  backoffice) y crear `class/actions_sydturnstile.class.php` (no existe hoy — este módulo nunca
  tuvo una clase de acciones), con dos métodos:
  - `PrintPageView($parameters, &$object, &$action, $hookmanager)`: si
    `$parameters['controller'] == 'login'`, instancia `modCaptchaTurnstile` e imprime
    `getCaptchaCodeForForm()` vía `$this->resprints`; retorna `0` siempre (aditivo, nunca reemplaza
    la plantilla).
  - `beforeLoginAuthentication($parameters, &$object, &$action, $hookmanager)`: solo cuando viene
    un intento de login tradicional (`action_login=login` en POST, no cuando el flujo es SSO —
    Microsoft ya no pasa por este bloque, entra por su propio callback), valida con
    `validateCodeAfterLoginSubmit()`; si falla y `SYDTURNSTILE_FAILOPEN=0`, retorna `< 0` con el
    mensaje de error (bloquea, según la regla del §0 solo si el fail-open está explícitamente
    apagado); si `SYDTURNSTILE_FAILOPEN=1` (el valor real hoy en dpsdocs) nunca bloquea el acceso.
- **Verificar antes de codear**: el nombre exacto del parámetro/contexto con el que
  `beforeLoginAuthentication` distingue "vengo del formulario tradicional" — ya se vio que el hook
  recibe `webportal_sessionname`/`webportal_anti_spam_session_key` en `$parameters`, confirmar que
  alcanza para no interferir con un login que no sea por formulario.
- **Checkpoint**: widget de Turnstile visible en el login real del webportal (navegador), envío
  válido pasa, envío simulando fallo de Cloudflare (o clave incorrecta a propósito, solo en un
  ensayo controlado) **no bloquea** el acceso mientras `SYDTURNSTILE_FAILOPEN=1`.

### E2 — Verificación cruzada con skin y SSO (no bloqueante, al cierre)
- Confirmar en navegador que el widget conviven visualmente con el botón de Microsoft (E0.6.1 de
  sydmsftgraph) y con el fondo/tarjeta ya corregidos por sydsupportbranding (E1/E2 de
  `PLAN-WEBPORTAL-SKIN.md`) — mismo hook `PrintPageView`, así que el orden de impresión entre
  módulos (cuál aparece primero) se revisa aquí, sin necesidad de coordinarlos por código.

---

## 3. Fuera de alcance
- Skin/CSS — `~/dev/sydsupportbranding/PLAN-WEBPORTAL-SKIN.md`.
- SSO Microsoft/Google — `~/dev/sydmsftgraph/PLAN-O365.md` E0.6.
- Resolver la colisión de número de módulo 149045 vs `sydkanban` — anotada arriba, no es parte de
  esta funcionalidad.
- Tocar el captcha del backoffice/tickets, ya funcionando — este plan solo agrega el webportal
  como una superficie nueva, no modifica `module_parts['captcha']` ni `modCaptchaTurnstile`.
