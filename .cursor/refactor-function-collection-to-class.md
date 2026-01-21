## Task: Refactor function/utils collection file to static helper class

You are working inside the **Contentsync** WordPress plugin.

Refactor **one function or utils collection file** (typically named with prefixes like `functions-` or `utils-`) into a **static helper class** that follows the conventions in `DEVELOPMENT.md`, and update all usages across the plugin.

> **Important**: If the file contains **root-level WordPress hook registrations** (`add_action`, `add_filter` called when the file is loaded), these must be **extracted** into a separate hooks provider class. Hooks registered *inside* functions (conditional/modular registrations) do **not** need extraction. See the [Hooks Extraction](#hooks-extraction) section below and follow the detailed guidelines in `.cursor/refactor-hooks-to-hook-class.md`.

### Conventions (must follow)

- **Root namespace**: `Contentsync`.
- **All code under `includes/`** is inside the `Contentsync\…` namespace.
- **Namespace = folder structure under `includes/`**.
- **Class and file naming**:
  - Remove `functions-` / `utils-` (and similar) prefixes from the file name.
  - Use `Word1_Word2` style (capitalized words separated by underscores) for class and file name.
  - File name matches the class name exactly: e.g. `Theme_Posts.php` defines `class Theme_Posts`.
- **Methods**:
  - All previous global/namespaced functions in that file become `public static` methods of the new class.

### Steps

1. **Understand the source file**
   - Identify:
     - Current namespace (it should already be `Contentsync\…`).
     - All functions defined in the file (ignore WordPress core functions).
     - **Any `add_action()` or `add_filter()` calls** (hook registrations).
     - **Any functions that serve as hook callbacks** (referenced in the hook registrations).
   - Confirm this file is a **function or utils collection** (no complex class hierarchy).

2. **Detect and extract root-level hooks (if present)**
   - Scan for `add_action()` or `add_filter()` calls **at the root level of the file** (executed when the file is loaded).
   - **Only root-level hooks need extraction.** Hooks registered *inside* functions are conditional/modular and can stay.
   - If the file contains root-level hooks:
     - These hooks **must be extracted** into a separate hooks provider class.
     - **Do not** leave root-level hook registrations in the static helper class.
   - Identify:
     - All root-level hook registrations (`add_action`, `add_filter`).
     - The callback function for each hook.
   - Callback extraction rules:
     - If the callback is **only** used by that hook → move it to the hooks class.
     - If the callback is **also referenced elsewhere** (called directly, used by other code) → keep it in the helper class; the hooks class calls `Helper_Class::method()`.
   - **Follow the guidelines in `.cursor/refactor-hooks-to-hook-class.md`** to create the hooks provider class.
   - The hooks class should:
     - Be named `{Feature}_Hooks.php` (e.g., if refactoring `functions-theme-posts.php`, create `Theme_Posts_Hooks.php`).
     - Extend `Contentsync\Utils\Hooks_Base`.
     - Place hook registrations in `register()`, `register_frontend()`, or `register_admin()` as appropriate.
     - Be instantiated in the plugin bootstrap/loader.

3. **Choose class and file name** (for the static helper class)
   - Derive the namespace from its folder under `includes/`.
   - Choose a class name that:
     - Describes the logical group (e.g. `Theme_Posts`, `Post_Query`, `Utils_Urls`).
     - Uses capitalized words with underscores.
   - Rename the file to `Class_Name.php` (no prefix, matches the class).

4. **Create the class**
   - Wrap the existing namespace line and function definitions with a class definition:
     - `class Class_Name { … }`
   - For each function:
     - Move it inside the class.
     - Convert it to a `public static` method.
     - Keep parameter lists, types, and return types unchanged.
     - If it referenced other functions in the same file, update them to `self::method_name()` or `Class_Name::method_name()` as appropriate.

5. **Update internal references within the file**
   - Replace any calls to local functions like `some_helper()` with `self::some_helper()` (or `static::` if needed).
   - Keep behavior identical.

6. **Update all usage across the plugin**
   - Search the entire plugin for calls to the old functions:
     - Direct calls: `function_name(...)`.
     - Namespaced calls: `\Contentsync\Something\function_name(...)`.
     - Hook callbacks: `'function_name'`, `'Contentsync\Something\function_name'`.
   - For **normal calls**:
     - Add `use Contentsync\Full\Namespace\Class_Name;` at the top of each file that uses these helpers.
     - Replace calls: `function_name(...)` → `Class_Name::function_name(...)`.
   - For **hook callbacks**:
     - Prefer using either:
       - `[ Class_Name::class, 'function_name' ]`, or
       - `'Contentsync\Full\Namespace\Class_Name::function_name'`.
     - Ensure the chosen pattern is consistent within the file.
   - The goal: at the top of every file, you can see which helper classes are used via `use` statements, and function calls become clearly scoped static method calls.

7. **Preserve behavior**
   - Do **not** change what the functions do.
   - Do not change parameters or return types except when absolutely necessary to make methods static-safe.
   - Ensure all call sites are updated; there should be **no remaining** references to the old standalone functions.

8. **Final checks**
   - The refactored file:
     - Resides under `includes/…`.
     - Uses a `Contentsync\…` namespace matching its path.
     - Contains one `Class_Name` with static methods.
   - All previous functions are now methods of this class.
   - All references in the plugin use `use Class_Name;` + `Class_Name::method()` or appropriate static callbacks.
   - If hooks were extracted: verify the new hooks class is registered in the plugin loader.

---

### Hooks Extraction

When a function collection file contains **root-level** WordPress hooks, you must split the refactoring into **two outputs**:

1. **Static helper class** — Contains pure utility/helper functions.
2. **Hooks provider class** — Contains hook registrations and their callbacks.

#### What qualifies as hooks that NEED extraction?

**Root-level hook registrations** — `add_action()` or `add_filter()` calls that execute when the file is loaded:

```php
// ❌ NEEDS EXTRACTION — root level, runs on file load
add_action( 'init', __NAMESPACE__ . '\schedule_daily_cleanup' );

function schedule_daily_cleanup() {
    // ...
}

// ❌ NEEDS EXTRACTION — root level
add_action( 'contentsync_distribute_item', __NAMESPACE__ . '\\distribute_item', 10, 1 );
```

#### What does NOT need extraction?

**Hooks inside functions** — conditional/modular registrations that only run when the function is called:

```php
// ✅ FINE — inside a function, conditional registration
public function switch_to_blog( $blog_id ) {
    if ( ! has_filter( 'upload_dir', array( __CLASS__, 'filter_wp_upload_dir' ) ) ) {
        add_filter( 'upload_dir', array( __CLASS__, 'filter_wp_upload_dir' ), 98, 1 );
    }
}

// ✅ FINE — inside a function, can be registered/unregistered dynamically
function enable_feature() {
    add_action( 'save_post', __NAMESPACE__ . '\on_save_post' );
}

function disable_feature() {
    remove_action( 'save_post', __NAMESPACE__ . '\on_save_post' );
}
```

#### Callback extraction rules

| Scenario | Action |
|----------|--------|
| Callback is **only** used by that hook | Move callback **with** the hook to hooks class |
| Callback is **also referenced elsewhere** | Keep callback in helper class; hooks class calls `Helper_Class::method()` |
| Pure utility function (no hook involvement) | Keep in static helper class |

#### Hooks class creation

Follow the complete guidelines in **`.cursor/refactor-hooks-to-hook-class.md`**.

Key points:
- Class name: `{Feature}_Hooks` (e.g., `Theme_Posts_Hooks`).
- Extends: `Contentsync\Utils\Hooks_Base`.
- Methods: `register()`, `register_frontend()`, `register_admin()`.
- Callbacks use `[ $this, 'method_name' ]` for instance methods.
- Register the hooks class in the plugin bootstrap (instantiate it).

#### Example split

**Before** (`functions-distribution-item.php`):
```php
namespace Contentsync\Distribution;

// Root-level hooks — NEED EXTRACTION
add_action( 'init', __NAMESPACE__ . '\schedule_daily_cleanup' );
add_action( 'contentsync_daily_cleanup', __NAMESPACE__ . '\delete_old_items' );

function schedule_daily_cleanup() {
    if ( ! wp_next_scheduled( 'contentsync_daily_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'contentsync_daily_cleanup' );
    }
}

function delete_old_items() {
    // deletes old items — only used by the hook
}

function get_distribution_item( $id ) {
    // pure query helper — used elsewhere in the plugin
}

function distribute_item( $item ) {
    // business logic — called by hooks AND directly by other code
}
```

**After** — two files:

1. `Distribution_Item.php` (static helper class):
```php
namespace Contentsync\Distribution;

class Distribution_Item {

    public static function get_distribution_item( $id ) {
        // pure query helper
    }

    // Keep here because it's also called directly elsewhere
    public static function distribute_item( $item ) {
        // business logic
    }
}
```

2. `Distribution_Item_Hooks.php` (hooks provider class):
```php
namespace Contentsync\Distribution;

use Contentsync\Utils\Hooks_Base;

class Distribution_Item_Hooks extends Hooks_Base {

    public function register(): void {
        add_action( 'init', [ $this, 'schedule_daily_cleanup' ] );
        add_action( 'contentsync_daily_cleanup', [ $this, 'delete_old_items' ] );
    }

    // Moved here — only used by the hook
    public function schedule_daily_cleanup(): void {
        if ( ! wp_next_scheduled( 'contentsync_daily_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'contentsync_daily_cleanup' );
        }
    }

    // Moved here — only used by the hook
    public function delete_old_items(): void {
        // deletes old items
    }
}
```

Then instantiate `new Distribution_Item_Hooks();` in the plugin loader.

#### Key points

- `delete_old_items()` and `schedule_daily_cleanup()` moved to hooks class — they're only used as hook callbacks.
- `distribute_item()` stays in the helper class — it's called elsewhere, so the hooks class would call `Distribution_Item::distribute_item()` if needed.
- Hooks inside functions (conditional registrations like `has_filter` checks) stay in the helper class.