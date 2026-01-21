## Task: Refactor hooks into hook provider class extending base hooks

You are working inside the **Contentsync** WordPress plugin.

Refactor **one hooks-containing file** into a **hook provider class** that follows the conventions below and uses a shared hooks base class.

This applies to:
- Files already named like `hooks-*.php`.
- Files where hooks (`add_action`, `add_filter`) are mixed into a functions file.

---

### DO NOT (critical)

- **DO NOT search for `require`, `include`, or autoload statements.** They don't exist and are not relevant.
- **DO NOT modify the main plugin file** (`contentsync.php`) or any loader/bootstrap file.
- **DO NOT add instantiation** (`new ClassName()`) anywhere. Hook classes are auto-discovered.
- **DO NOT investigate how files are loaded.** Just create the class file and delete the old file.

**Why no instantiation?** The plugin has a `Hooks_Loader` (`includes/Utils/Hooks_Loader.php`) that automatically discovers all `*_Hooks.php` files in `includes/` and instantiates them. Just create the file with the correct naming convention and it will be loaded automatically.


---

### Conventions (must follow)

- **Root namespace**: `Contentsync`.
- **All code under `includes/`** is inside the `Contentsync\…` namespace.
- **Namespace = folder structure under `includes/`**.
- **Class and file naming**:
  - Hook classes end in `_Hooks` (e.g. `Post_Transfer_Hooks`, `Reviews_Mails_Hooks`).
  - Remove any `hooks-` prefix from the file name.
  - Use `Word1_Word2_Hooks` style with capitalized words and underscores.
  - File name equals the class name: `Post_Transfer_Hooks.php` defines `class Post_Transfer_Hooks`.
- **Base class**:
  - Hook provider classes extend: `Contentsync\Utils\Hooks_Base`.
  - Override these methods as needed:
    - `public function register()` — hooks that run everywhere
    - `public function register_frontend()` — frontend-only hooks
    - `public function register_admin()` — admin-only hooks

---

### Steps

1. **Read the target file**
   - Identify all `add_action()` and `add_filter()` calls.
   - Identify the callback functions for each hook.

2. **Create the hook provider class**
   - Use the class name provided by the user (or derive one ending in `_Hooks`).
   - Create the file in the same directory as the old file.
   - Namespace matches the folder path under `includes/`.

```php
<?php

namespace Contentsync\Some\Subnamespace;

use Contentsync\Utils\Hooks_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Some_Module_Hooks extends Hooks_Base {

	public function register() {
		// Common actions/filters (optional)
	}

	public function register_frontend() {
		// Frontend-only actions/filters (optional)
	}

	public function register_admin() {
		// Admin-only actions/filters (optional)
	}
}
```

   - If the plugin’s base hooks class uses a different namespace or method signatures, adapt accordingly.

3. **Move hook registrations into the class**
   - Put hooks in the appropriate method:
     - `register()` — hooks that run on both frontend and admin (most common)
     - `register_frontend()` — frontend-only hooks
     - `register_admin()` — admin-only hooks
   - Convert callbacks from `__NAMESPACE__ . '\function_name'` to `array( $this, 'method_name' )`.

4. **Convert callback functions to class methods**
   - Move each callback function into the class as a public method.
   - Keep the same logic and parameters.

5. **Delete the original file**
   - Delete the old `hooks-*.php` file entirely.

6. **Done** — Do not modify any other files.

---

### Summary

This is a simple, mechanical refactor:
1. Read the old hooks file.
2. Create the new class file with the hooks and callbacks as methods.
3. Delete the old file.
4. That's it. No other changes needed.
