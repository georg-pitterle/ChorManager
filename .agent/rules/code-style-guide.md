---
trigger: always_on
description: Code Style Guide for Slim Framework App
---

# Coding Standard Rule

For this Slim Framework application, we strictly adhere to the PHP-FIG (PHP Framework Interoperability Group) recommended standard, specifically **PSR-12** (Extended Coding Style).

## Key Guidelines (PSR-12)

1. **Indentation:** Use 4 spaces for indentation (no tabs).
2. **Line Length:** There is a soft limit on line length of 120 characters; lines SHOULD be 80 characters or less.
3. **File Naming & Namespacing:**
   - Every file must have exactly one class/interface/trait (adhering to PSR-4 Autoloading).
   - Namespaces and class names must match the directory structure and file names.
4. **Visibility:** All properties and methods MUST declare visibility (`public`, `protected`, or `private`).
5. **Braces:**
   - Control structures (`if`, `foreach`, `while`) should have their opening brace on the same line.
   - Classes and Methods must have their opening brace on the next line.
6. **Types:** Return types and argument types should be specified wherever possible.
7. **Control Structures:** There must be one space after the control structure keyword, and no space after the opening parenthesis.

## Tools
To enforce this, we use `squizlabs/php_codesniffer`.

- **Check Code Standards:** Run `ddev composer phpcs` or `ddev exec phpcs src/`
- **Fix Code Standards:** Run `ddev composer phpcbf` or `ddev exec phpcbf src/`

*Always ensure code is formatted with PSR-12 before submitting a large change.*

*Do not format files in the 'vendor' folder

*Ensure the file uses Linux-style line endings (\n)*