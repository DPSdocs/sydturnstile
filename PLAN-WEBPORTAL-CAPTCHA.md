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

### Puntos de extensión reales del webportal (verificados en código Y en producción, sesión de hoy)
- `Controller::__construct()` (`webportal/class/controller.class.php`) ya llama
  `$hookmanager->initHooks(array('webportalpage', 'webportal'))` para **toda** página del
  webportal, login incluido — mismo contexto que ya usa `sydsupportbranding`.
- `LoginController::display()` hace
  `$hookRes = $this->hookPrintPageView(); if (empty($hookRes)) { $this->loadTemplate('login'); }`
  — un hook `PrintPageView` que retorna `0` **no reemplaza** la plantilla nativa, que sigue
  cargando debajo (mismo patrón que usará el botón de Microsoft en sydmsftgraph E0.6.1).
- 🔴 **Hallazgo real, encontrado al implementar y probar en producción**: a diferencia de
  `webPortalHeader` (que sí se imprime — `header.tpl.php:108-109` hace
  `$hookmanager->executeHooks('webPortalHeader', ...); print $hookmanager->resPrint;`),
  **ni `LoginController::display()` ni `header_login.tpl.php` imprimen `$hookmanager->resPrint`
  para el hook `PrintPageView`** (confirmado por grep: cero referencias a `resPrint` en ambos
  archivos). Asignar contenido a `$this->resprints` en este hook se pierde en silencio — no basta
  con el patrón que sí funciona para CSS/fuentes. La solución real, verificada: dentro del método
  `PrintPageView()`, imprimir con `echo` **directo** (no `$this->resprints =`) — como el método se
  ejecuta en el mismo punto donde `hookPrintPageView()` es llamado (justo después de
  `header_login`, antes de `login.tpl.php` si se retorna `0`), el contenido aparece exactamente
  donde debe, sin depender de que el llamador imprima nada.
- `webportal.main.inc.php` (~línea 144) dispara **siempre**, incluso sin
  `WEBPORTAL_LOGIN_BY_MODULE`, el hook `beforeLoginAuthentication` con contexto `login` — el mismo
  nombre de contexto que ya usa `sydmsftgraph` para el backoffice, así que declararlo aquí no
  colisiona (cada handler se registra por módulo, no por nombre de contexto global) — es el punto
  exacto donde validar la respuesta de Turnstile **antes** de que se intente
  `getThirdPartyAccountFromLogin()`. A diferencia de `PrintPageView`, aquí sí importa el valor
  numérico de retorno: `webportal.main.inc.php` hace
  `if ($reshook < 0) { $error++; } elseif (empty($reshook)) { /* flujo normal de login */ }` — un
  retorno `> 0` **también** reemplazaría todo el bloque de autenticación nativo (nunca se querría
  eso aquí), así que el hook debe retornar siempre `0` salvo cuando bloquea explícitamente
  (entonces `< 0`).

---

## 2. Épicas

### E1 — Declarar los hooks del webportal
- **Construir — ✅ IMPLEMENTADO Y DESPLEGADO 22-sep-2026**: en `modSydTurnstile`, se sumó
  `'hooks' => array('webportal', 'webportalpage', 'login')` a `module_parts` (sin tocar
  `'captcha' => 1`, que sigue siendo el mecanismo del backoffice) y se creó
  `class/actions_sydturnstile.class.php` (no existía — este módulo nunca tuvo una clase de
  acciones), con dos métodos:
  - `PrintPageView($parameters, &$object, &$action, $hookmanager)`: si
    `$parameters['controller'] == 'login'`, instancia `modCaptchaTurnstile` e imprime
    `getCaptchaCodeForForm()` con **`echo` directo** (no `$this->resprints`, ver hallazgo arriba);
    retorna `0` siempre (aditivo, nunca reemplaza la plantilla).
  - `beforeLoginAuthentication($parameters, &$object, &$action, $hookmanager)`: solo cuando
    `GETPOST('action_login', 'alphanohtml') === 'login'` (envío real del formulario tradicional —
    el login SSO de Microsoft nunca pasa por este bloque, entra por su propio callback), valida con
    `validateCodeAfterLoginSubmit()`; si falla, retorna `-1` con mensaje de error vía
    `$object->setEventMessage()`. `validateCodeAfterLoginSubmit()` ya aplica fail-open
    internamente (retorna `1` si `SYDTURNSTILE_FAILOPEN=1` y Cloudflare no responde, o si el
    módulo no está configurado) — el hook nunca necesita replicar esa lógica.
- **Dos bugs reales encontrados y corregidos durante el despliegue** (no eran evidentes desde la
  lectura de código, solo al probar contra la instancia real):
  1. `require_once DOL_DOCUMENT_ROOT.'/core/modules/security/captcha/modCaptchaTurnstile.class.php'`
     apuntaba al core nativo de Dolibarr, no a la clase real del módulo
     (`custom/sydturnstile/core/modules/security/captcha/...`) — fatal error silencioso al cargar
     el hook (visible en `dolibarr.log`, no en pantalla). Corregido a
     `dol_include_once('/sydturnstile/core/modules/security/captcha/modCaptchaTurnstile.class.php')`.
  2. El nuevo `module_parts['hooks']` del descriptor no tenía efecto por sí solo: Dolibarr no
     relee el archivo del descriptor en cada request — lee `$conf->modules_parts` desde constantes
     `MAIN_MODULE_SYDTURNSTILE_HOOKS` en `llx_const`, escritas una sola vez al activar el módulo
     (`DolibarrModules::_init()`). Hubo que re-ejecutar `init()` (equivalente a
     desactivar/reactivar desde la UI, hecho vía script CLI en vez de la UI para no arriesgar
     `SYDTURNSTILE_SITEKEY`/`SECRET`, que no están en `$this->const` y por tanto no se tocan por un
     `_remove()`/`_init()`) para que la constante se creara.
- **Checkpoint parcial verificado (navegador real)**: el widget de Turnstile carga en
  `https://erp.dpsdocs.pro/public/webportal/`, modo managed (invisible, como está documentado en
  `SydTurnstileExample`) — confirmado con JS que el campo oculto `cf-turnstile-response` se puebla
  con un token real de Cloudflare. **Pendiente**: probar un envío real del formulario tradicional
  (login/password de una de las 3 cuentas reales de `site='dolibarr_portal'`) para confirmar que
  `beforeLoginAuthentication` no bloquea el acceso — no se ejecutó en esta sesión por no tener
  credenciales de esas cuentas ni autorización para usarlas; queda para que el usuario (o el
  dueño de una de esas 3 cuentas) lo ejercite.

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
