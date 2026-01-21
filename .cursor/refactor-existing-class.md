## Task: Refactor existing class file to Contentsync conventions

You are working inside the **Contentsync** WordPress plugin.

Refactor **one existing class file** so that its file name, class name, and namespace follow the rules in `DEVELOPMENT.md`, without changing behavior.

### Conventions (must follow)

- **Root namespace**: `Contentsync`.
- **All code under `includes/`** is inside the `Contentsync\…` namespace.
- **Namespace = folder structure under `includes/`**. Example:
  - `includes/Posts/Transfer/Post_Export.php` → `namespace Contentsync\Posts\Transfer;`
- **Class name = file name (without `.php`)**.
- **Class and file naming**:
  - Remove legacy prefixes like `class-`, `functions-`, `hooks-`, `utils-` from file names.
  - Use words separated by underscores, each word capitalized: `Post_Export`, `Theme_Assets`, `Post_Import_Hooks`, etc.
  - File name matches exactly, e.g. `Post_Export.php` defines `class Post_Export`.

### Steps

1. **Understand the file**
   - Read the current class file.
   - Identify:
     - Current namespace.
     - Current class name.
     - Where it lives under `includes/`.

2. **Determine the correct namespace and class name**
   - Compute the namespace from the path under `includes/`:
     - Replace `/` with `\` and prepend `Contentsync\`.
   - Choose a class name that:
     - Uses `Word1_Word2` style with underscores and leading capitals.
     - Reflects the existing class responsibility.
   - Ensure the **file name** becomes `Class_Name.php` exactly.

3. **Update the file**
   - Adjust the `namespace` line to match the folder structure if needed.
   - Rename the class to the new `Word1_Word2`-style name.
   - Ensure the file name matches the new class name.

4. **Update all usages across the plugin**
   - Search the entire plugin for references to the old class name:
     - Direct references (`new OldClass`, `OldClass::method`, `Old\Namespace\OldClass`).
     - `use` statements.
     - String-based references (e.g. hook callbacks, `::class` usage).
   - Update all of them to use:
     - The correct new namespace (`Contentsync\…`).
     - The new class name.
   - Prefer using `use` statements at the top of each file where the class is used, so that usage looks like:
     - `use Contentsync\Posts\Transfer\Post_Export;`
     - Then `new Post_Export(...)` or `Post_Export::method(...)` in the body.

5. **Preserve behavior**
   - Do **not** change method signatures, visibility, or logic.
   - Only adjust:
     - Namespace.
     - Class name.
     - File name.
     - References to the class.

6. **Sanity check**
   - Ensure:
     - `namespace` matches folder structure from `includes/`.
     - File name matches class name exactly.
     - No references to the old class name remain in the plugin.

### Important notes

- **Don't investigate autoloading**: How files are loaded (PSR-4, manual requires, etc.) is not relevant to this task. Just rename and update references.
- **Don't search for require/include statements**: Focus only on class usage references (`use` statements, `ClassName::method()`, `new ClassName()`).
- **Keep it simple**: This is a mechanical rename operation. Read the file, determine the correct names based on conventions, rename, and update usages.