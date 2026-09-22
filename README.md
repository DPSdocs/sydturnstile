# SydTurnstile for [Dolibarr ERP CRM](https://www.dolibarr.org)

## Features

Replaces Dolibarr's native graphical captcha with [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/)
on the login form, password recovery and any public form that reads Dolibarr's
`MAIN_SECURITY_ENABLECAPTCHA_HANDLER` constant.

- Registers a new captcha handler (`ModeleCaptcha`) — no core file is modified, Dolibarr
  discovers it through `module_parts['captcha']`.
- In managed mode Turnstile usually asks the visitor nothing: it verifies the browser
  silently in the background instead of a puzzle or a distorted-text image.
- **Fail-open by default**: if Cloudflare's API cannot be reached, the login is still allowed
  through (configurable) so an outage on Cloudflare's side never locks everyone out of the ERP.
- Inert until both the site key and secret key are set on the module's setup page — installing
  it changes nothing until it is configured.

## Requirements

- Dolibarr 20.0 or later.
- PHP 7.4 or later.
- A free [Cloudflare account](https://dash.cloudflare.com/sign-up) with a
  [Turnstile](https://dash.cloudflare.com/?to=/:account/turnstile) widget for the instance's
  domain (site key + secret key). No paid plan required.

## Installation

### 1. Download

Get the zip from [DoliStore](https://www.dolistore.com) (menu **Home → Modules → Deploy an
external module** downloads and installs it in one step), or download the
`module_sydturnstile-x.y.z.zip` file directly if you got it another way.

### 2. Install it in Dolibarr

- **From the zip (recommended):** in Dolibarr, go to **Home → Setup → Modules → Deploy an
  external module**, upload the zip file, and Dolibarr extracts and installs it automatically.
- **Manually:** unzip it so the `sydturnstile` folder ends up in
  `htdocs/custom/sydturnstile` (or `htdocs/sydturnstile` if your instance has no `custom`
  directory).

### 3. Enable the module

Go to **Home → Setup → Modules**, find **SydTurnstile** in the list and enable it.

### 4. Create a Turnstile widget in Cloudflare

1. Log into the [Cloudflare dashboard](https://dash.cloudflare.com) (create a free account if
   you don't have one).
2. Go to **Turnstile** in the left menu and click **Add widget**.
3. Enter the domain of this Dolibarr instance and create the widget.
4. Copy the **Site Key** and the **Secret Key** it gives you.

### 5. Configure SydTurnstile

Open the module's setup page (**Home → Setup → Modules → SydTurnstile**, gear icon), paste the
site key and the secret key, and save. Optionally pick the widget theme (light / dark / auto)
and whether to fail open or closed if Cloudflare cannot be reached (fail open is recommended).

### 6. Turn the captcha on

Go to **Home → Setup → Security → Captcha code**, enable the captcha and pick the
**turnstile** handler from the list. That's it — the widget now shows on the login page.

## Translations

Included: `en_US`, `es_ES`. Other languages can be added by copying a `langs/xx_XX/sydturnstile.lang`
file and translating it.

## Licenses

### Main code

GPLv3 or (at your option) any later version. See file COPYING for more information.

### Documentation

All texts and readmes are licensed under GFDL.

## Support

- Issues / questions: [github.com/DPSdocs/sydturnstile/issues](https://github.com/DPSdocs/sydturnstile/issues)
- Company: [DPS Docs](https://www.dpsdocs.pro) — ventas@dpsdocs.pro
