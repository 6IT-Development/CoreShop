# FrontendBundle – TODO 1: Critical Errors

Target: **Symfony 7.4**, **PHP 8.4**. Scan date: 2026-10-26 (branch `s74-p84`).
Scope: all PHP in this bundle (`Controller/**`, `Command/**`, `DependencyInjection/**`, `Installer/**`, `TemplateConfigurator/**`, `Twig/**`, `Form/**`, bundle class), `Resources/config/**` (services, frontend-installer, pimcore config/routing), `Resources/install/pimcore/staticroutes.yml`, translations and `composer.json`. Templates (`Resources/views/**`) were checked where PHP code renders them or they call back into PHP (`render(controller(...))`, `_redirect`, `template`). Also checked: the code these controllers depend on or that builds on them:
`TestBundle/Resources/config/twig.yml` + `TestBundle/Twig/Extension/*`, `CoreBundle/Security/AccessVoter.php`, `CoreBundle/Resources/config/{pimcore/security.yml,services/commands.yml}`, `CoreBundle/Command/AbstractInstallCommand.php`, `CoreBundle/Form/Type/Order/PaymentType.php`, `CustomerBundle/Form/Type/ChangePasswordType.php`, `UserBundle/Form/Type/RequestResetPasswordType.php`, `UserBundle/Pimcore/Repository/UserRepository.php`, `PayumBundle/Controller/PaymentController.php`, `PayumBundle/Action/{,Offline/}ResolveNextRouteAction.php`, `ResourceBundle/Pimcore/{PimcoreDaoRepository,PimcoreRepository}.php`, `ResourceBundle/DependencyInjection/Extension/AbstractModelExtension.php`, `docs/03_Development/15_Frontend_Bundle/02_Best_Practices.md`, and the Pimcore `Staticroute::assemble()` code (`../pimcore-source/bundles/StaticRoutesBundle`).

"Critical" here means: a security issue, data loss, or an error that takes down every request (or a whole area) once it is triggered.
Paths are relative to the bundle root unless noted otherwise. Items marked **(dependent)** live outside this bundle, but they come from how this bundle uses them, and they should be fixed together with it.

**Symfony 7.4 / PHP 8.4 compatibility:** no hard language or API blockers found. `php -l` (PHP 8.4.27) passes on every PHP file in the bundle. There are no implicit nullable parameters. Service-subscriber wiring was checked against `symfony/dependency-injection` 7.4.21 (`RegisterServiceSubscribersPass`). Controller-string resolution was checked against `symfony/http-kernel` 7.4.21 (`ControllerResolver::createController`). The 7.4 deprecation that matters here is `Request::get()`, deprecated since 7.4. `FrontendController::getParameterFromRequest()` copies its behaviour without calling it (see TODO-3 I-06). Everything else about modernisation is in TODO-3.

Only two items reach the "critical" bar. The security findings (open redirect, arbitrary template rendering, GET/CSRF state changes, IDOR on the revise page) are serious, but each one is limited in scope, so they lead TODO-2 instead.

Related files: [TODO-2-BUGS.md](TODO-2-BUGS.md), [TODO-3-IMPROVEMENTS.md](TODO-3-IMPROVEMENTS.md)

---

## C-01 — `services.yml` imports a file from `TestBundle` by relative monorepo path, so the container cannot be built when `coreshop/frontend-bundle` is installed as its own package

- **Where:**
  - `Resources/config/services.yml:2`: `- { resource: ../../../TestBundle/Resources/config/twig.yml }`
  - `composer.json:26-29`: requires only `coreshop/core-bundle` and `six-it/pimcore-source`. There is no `coreshop/test-bundle`.
  - (dependent) `TestBundle/composer.json`: `coreshop/test-bundle` requires `behat/behat`, `robertfausk/behat-panther-extension`, … (test-only dependencies).
  - 162 calls to `coreshop_test_html_attribute()` / `coreshop_test_form_attribute()` in `Resources/views/**`, for example `Security/_login-form.html.twig:20-32`.
- **Problem:**
  - The path only resolves inside the monorepo (`src/CoreShop/Bundle/FrontendBundle/../../../TestBundle`). When the split package is installed (`vendor/coreshop/frontend-bundle`), it points at `vendor/coreshop/TestBundle/...`, which does not exist. The split directory is `vendor/coreshop/test-bundle`, and the package is not installed anyway. `YamlFileLoader` then throws `FileLocatorFileNotFoundException` during `CoreShopFrontendExtension::load()`. **The kernel cannot boot, so every request and every console command fails.**
  - Even where the file exists, production now depends on a test bundle's classes (`CoreShop\Bundle\TestBundle\Twig\Extension\*`), and every storefront template calls Twig functions that only that bundle defines. If the import is removed without a replacement, every template fails with `Unknown "coreshop_test_html_attribute" function`.
- **Fix direction:**
  - Move the two tiny extensions (`coreshop_test_html_attribute`, `coreshop_test_form_attribute`) into this bundle, or into CoreBundle, as production code that returns `''` outside `test*` environments. Register them in `services.yml` and delete the cross-bundle import.
  - Alternatively, provide no-op fallbacks here and let TestBundle override them when it is registered.
  - Add a CI job that installs the split package on its own (`composer require coreshop/frontend-bundle` in a skeleton) and runs `bin/console lint:container` and `lint:twig`.

## C-02 — `coreshop:frontend:install` (the documented way to customise the storefront) writes templates whose `extends`/`include`/`import` paths do not resolve, so every storefront page fails afterwards

- **Where:**
  - `Installer/TemplatesInstaller.php:43-54`: files are copied to `<templatePath>/coreshop/<relative path>`, but the content is rewritten with `str_replace(['@CoreShopFrontend/', 'bundles/coreshopfrontend/'], ['', 'coreshop/'], …)`.
  - `Installer/TemplateConfiguratorInstaller.php:30-47`: sets `core_shop_frontend.view_prefix: 'coreshop'`, so controllers render `coreshop/<Name>.html.twig`.
  - Templates reference the bundle namespace everywhere, for example `'@CoreShopFrontend/layout-full.html.twig'` (14×), `'@CoreShopFrontend/layout.html.twig'` (8×), and macro imports such as `"@CoreShopFrontend/Common/Macro/currency.html.twig"` (20×).
  - `docs/03_Development/15_Frontend_Bundle/02_Best_Practices.md`: "This will copy all the templates from CoreShop into `coreshop` and it also replaces all the Bundle Prefixes".
- **Problem:**
  - The controller renders `coreshop/Product/detail.html.twig`, which resolves correctly. That template then does `{% extends 'layout-full.html.twig' %}` (the prefix was replaced with `''`, not `'coreshop/'`). Twig looks for `templates/layout-full.html.twig`, but the file is at `templates/coreshop/layout-full.html.twig`.
  - Result: `Twig\Error\LoaderError: Unable to find template` on every page that extends a layout or imports a macro, which is essentially the whole storefront.
  - If the project happens to have its own `templates/layout.html.twig`, the copied templates silently extend the wrong layout instead.
  - The asset replacement (`bundles/coreshopfrontend/` → `coreshop/`) is consistent with `PublicInstaller` (`public/coreshop/static`). Only the template prefix is wrong.
- **Fix direction:**
  - Replace `'@CoreShopFrontend/'` with `'coreshop/'`. More precisely, use `rtrim($viewPrefix, '/') . '/'`, derived from the same value `TemplateConfiguratorInstaller` writes, so the two cannot drift apart. Keep the `--templatePath` problem in mind (TODO-2 B-23).
  - Add a test that installs into a temp dir, boots a Twig `FilesystemLoader` on it, and runs `lint:twig` on the result.
  - **Verify on a fresh skeleton before ranking:** no `twig.paths` entry that maps `templates/coreshop` as a root was found in this repo or in CoreBundle config. If a project adds one, the bug is masked.
