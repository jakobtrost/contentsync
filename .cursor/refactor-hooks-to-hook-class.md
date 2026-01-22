## Task: Refactor hooks into hook provider class extending base hooks

You are working inside the **Contentsync** WordPress plugin.

Refactor **one hooks-containing file** into a **hook provider class** that follows the conventions below and uses a shared hooks base class.

This applies to:
- Files already named like `hooks-*.php`.
- Files where hooks (`add_action`, `add_filter`) are mixed into a functions file.

---

### DO NOT (critical)

- **DO NOT search for `require`, `include`, or autoload statements.** They don't exist and are not relevant.
- **DO NOT add instantiation** (`new ClassName()`) anywhere for hook registration. Hook classes are auto-discovered.
- **DO NOT investigate how files are loaded.** Just create the class file and delete the old file.

**Why no instantiation for hooks?** The plugin has a `Hooks_Loader` (`includes/Utils/Hooks_Loader.php`) that automatically discovers all `*_Hooks.php` files in `includes/` and instantiates them. Just create the file with the correct naming convention and it will be loaded automatically.

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
   - Note all function names defined in the file.

2. **Search for existing calls to these functions**
   - Use grep/search to find any direct calls to the functions throughout the codebase.
   - Check especially:
     - `contentsync.php` (activation/deactivation hooks)
     - Other files that might call these functions directly
   - Note any calls found — they must be handled in step 7.

3. **Create the hook provider class**
   - Use the class name provided by the user (or derive one ending in `_Hooks`).
   - Create the file in the same directory as the old file.
   - Namespace matches the folder path under `includes/`.

```php
<?php

namespace Contentsync\Some\Subnamespace;

use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

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

   - If the plugin's base hooks class uses a different namespace or method signatures, adapt accordingly.

4. **Move hook registrations into the class**
   - Put hooks in the appropriate method:
     - `register()` — hooks that run on both frontend and admin (most common)
     - `register_frontend()` — frontend-only hooks
     - `register_admin()` — admin-only hooks
   - Convert callbacks from `__NAMESPACE__ . '\function_name'` to `array( $this, 'method_name' )`.
   - **Exception**: If a method is called externally (found in step 2), use `array( self::class, 'method_name' )` instead.

5. **Convert callback functions to class methods**
   - Move each callback function into the class as a public method.
   - Keep the same logic and parameters.
   - **If a method is called externally** (found in step 2):
     - Make it `public static` instead of `public`.
     - Change any internal `$this->other_method()` calls within that method to `self::other_method()`.
     - Make any helper methods it calls also `public static`.

6. **Delete the original file**
   - Delete the old `hooks-*.php` file entirely.

7. **Update external calls (if any found in step 2)**
   - This is an **edge case** that should be reported to the user.
   - For each file that calls a function from the refactored file:
     - Add a `use` statement at the top of the file: `use Contentsync\Namespace\ClassName;`
     - Update the call from `\Namespace\function_name()` to `ClassName::method_name()`
     - Remove any `require` or `include` statements for the deleted file.
   - Example:
     ```php
     // At top of file, after namespace declaration:
     use Contentsync\DB\Database_Tables_Hooks;
     
     // Later in code:
     Database_Tables_Hooks::maybe_add_tables();
     ```

8. **Report external usage to user (important!)**
   - If any external calls were found and updated, **tell the user**:
     - Which method(s) are now static
     - Which file(s) are calling them
     - That this is an edge case and **should likely be refactored** in the future to avoid cross-file dependencies on hook class methods.
   - This helps the user track technical debt and plan future refactoring.

9. **Done**

---

### Summary

1. Read the old hooks file and identify all functions.
2. Search for external calls to those functions in the codebase.
3. Create the new class file with the hooks and callbacks as methods.
4. If methods are called externally, make them static.
5. Delete the old file.
6. Update external callers: add `use` statement, call `ClassName::method()`.
7. **Report any external usage to the user** — this is an edge case that should be refactored later.
