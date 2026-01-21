## Task: Refactor hooks into hook provider class extending base hooks

You are working inside the **Contentsync** WordPress plugin.

Refactor **one hooks-containing file** into a **hook provider class** that follows the conventions in `DEVELOPMENT.md` and uses a shared hooks base class. Update the plugin so the new hook provider is registered appropriately.

This applies to:
- Files already named like `hooks-*.php`.
- Files where hooks (`add_action`, `add_filter`) are mixed into a functions file.

### Conventions (must follow)

- **Root namespace**: `Contentsync`.
- **All code under `includes/`** is inside the `Contentsync\…` namespace.
- **Namespace = folder structure under `includes/`**.
- **Class and file naming**:
  - Hook classes end in `_Hooks` (e.g. `Post_Import_Hooks`, `Reviews_Mails_Hooks`).
  - Remove any `hooks-` prefix from the file name.
  - Use `Word1_Word2_Hooks` style with capitalized words and underscores.
  - File name equals the class name: `Post_Import_Hooks.php` defines `class Post_Import_Hooks`.
- **Base class**:
  - Hook provider classes extend the shared base: `Contentsync\Utils\Hooks_Base`.
  - The base class exposes:
    - `public function register(): void`
    - `public function register_frontend(): void`
    - `public function register_admin(): void`
  - Concrete hook classes override these as needed.

### Steps

1. **Identify hooks and their context**
   - Read the target file.
   - Collect all `add_action()` and `add_filter()` calls that belong to this logical area (e.g. “post import hooks”).
   - If hooks are mixed into a larger functions file:
     - Separate pure helpers from hooks.
     - Only the hooks and their callback methods go into the new hook class.
     - Keep existing helper logic in helper/service classes as per the function-refactor guidelines.

2. **Create the hook provider class**
   - Determine the namespace from the folder under `includes/`.
   - Choose a class name ending in `_Hooks` that describes the area, e.g.:
     - `Post_Import_Hooks` for `includes/Posts/Transfer/…`.
   - Create a file named `Class_Name.php` (e.g. `Post_Import_Hooks.php`) and define:

```php
namespace Contentsync\Some\Subnamespace;

use Contentsync\Utils\Hooks_Base;

class Subnamespace_Module_Hooks extends Hooks_Base {

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
   - Move each `add_action` / `add_filter` from the old file into the appropriate method:
     - Hooks that should run on both sides: stay in `register()`.
     - Frontend-only hooks: in `register_frontend()`.
     - Admin-only hooks: in `register_admin()`.
   - Hook callbacks should be methods on this class or on another appropriate class:
     - For callbacks defined here, use `[ $this, 'method_name' ]`.
     - For callbacks on other classes, follow the class-based patterns from the function refactor.

4. **Adjust or create helper classes if needed**
   - If some hook callbacks were previously standalone functions in this file:
     - Move those helpers into a separate helper/service class (static or instance) following the function-refactor conventions.
     - In the hook provider, call those helpers via `Helper_Class::method()` or injected services, rather than keeping large logic bodies in the hook methods.

5. **Update plugin bootstrap/loader**
   - Identify where these hooks were previously registered/loaded (often just by including the old file).
   - Replace that with explicit registration of the new hook class:
     - Import the class at the top: `use Contentsync\Some\Subnamespace\Post_Import_Hooks;`
     - Instantiate and register in the appropriate context:
       - For example, in a module loader or main loader:

```php
new Post_Import_Hooks();
```

6. **Clean up the original file**
   - If the old file was only hooks:
   		- It should now be replaced entirely by the new hook provider class file with proper naming.
   - If hooks were mixed with other functions:
   		- Remove the hook registrations from the original file.
   		- Ensure any remaining helpers there are refactored later according to the function-collection guidelines.

7. **Preserve behavior**
   - Do not change which hooks are registered or when they run (admin vs frontend), except to make the context boundaries explicit.
   - Ensure all previous add_action/add_filter calls still exist, just moved and possibly restructured.

8. **Final checks**
   - New hook class:
   		- Extends the common hooks base class.
   		- Lives in the correct Contentsync\… namespace.
   		- Resides in a file that matches its class name.
   - No old, unused hook registrations remain in the former file.
   - All context-sensitive hooks are correctly grouped into register_frontend() / register_admin() and wired from the loader.