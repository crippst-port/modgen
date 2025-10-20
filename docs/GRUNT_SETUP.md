# Grunt Build Setup Guide

## Overview
This project uses **Grunt** to automatically minify JavaScript files. The Grunt build system ensures consistent, optimized JavaScript for production use.

## Files
- `Gruntfile.js` - Grunt configuration (defines build tasks)
- `package.json` - NPM dependencies (Grunt plugins)
- `.gitignore` - Excludes node_modules from git

## Installation (One-Time Setup)

```bash
cd /Users/tomcripps/Sites/moodle45/ai/placement/modgen
npm install
```

This installs:
- `grunt` - Task automation tool
- `grunt-contrib-uglify` - JavaScript minification plugin
- `grunt-contrib-watch` - File watching for auto-minification

## Usage

### Build (Minify JavaScript)
```bash
npm run build
# or
npx grunt
```

Converts:
- `amd/src/explore.js` (20.4 kB) → `amd/build/explore.min.js` (4.43 kB)

**Output:**
```
Running "uglify:dist" (uglify) task
>> 1 file created 20.4 kB → 4.43 kB
Done.
```

### Watch Mode (Auto-Minify on Save)
```bash
npm run watch
# or
npm run dev
# or
npx grunt watch
```

Automatically minifies when you save changes to `amd/src/**/*.js`

### Available Commands
| Command | Task |
|---------|------|
| `npm run build` | Minify JavaScript files |
| `npm run watch` | Watch and auto-minify |
| `npm run dev` | Alias for watch mode |
| `npx grunt` | Run default task (build) |
| `npx grunt watch` | Run watch task |

## Workflow

### Development Flow
1. Edit `amd/src/explore.js` (source file)
2. Run `npm run build` to minify
3. Commit both files:
   ```bash
   git add amd/src/explore.js amd/build/explore.min.js
   git commit -m "Update explore.js"
   ```

### Continuous Development (Recommended)
```bash
# Terminal 1: Start watch mode
npm run watch

# Terminal 2: Make edits and commit
# (minification happens automatically)
```

## Configuration Details

### Minification Options (Gruntfile.js)
```javascript
compress: {
    sequences: true,      // Combine consecutive var statements
    dead_code: true,      // Remove unreachable code
    conditionals: true,   // Optimize if/else statements
    booleans: true,       // Optimize boolean operators
    unused: true,         // Remove unused variables
    if_return: true,      // Optimize if/return combinations
    join_vars: true       // Join var statements
},
mangle: true,             // Shorten variable names (a, e, n, etc.)
output: {
    comments: false       // Strip all comments (except banner)
}
```

### Banner
Each minified file includes:
```javascript
/*! aiplacement_modgen - v1.0.0 - 2025-10-20
 * @license GPL-3.0-or-later or later
 */
```

## File Size Reduction

| File | Original | Minified | Reduction |
|------|----------|----------|-----------|
| explore.js | 20.4 kB | 4.43 kB | 78.3% |

## Troubleshooting

### "grunt: command not found"
Use `npx grunt` instead:
```bash
npx grunt
npx grunt watch
```

### Dependencies not installed
```bash
npm install
```

### Want to reinstall everything
```bash
rm -rf node_modules package-lock.json
npm install
```

### Check if Grunt is installed
```bash
npm list grunt
npm list grunt-contrib-uglify
npm list grunt-contrib-watch
```

## What Gets Committed to Git

✅ **DO commit:**
- `Gruntfile.js` - Build configuration
- `package.json` - Dependencies list
- `package-lock.json` - Dependency lock file
- `amd/src/explore.js` - Source JavaScript
- `amd/build/explore.min.js` - Minified JavaScript

❌ **DON'T commit:**
- `node_modules/` - Excluded via .gitignore (install locally with npm)

## Further Reading

- [Grunt Documentation](https://gruntjs.com/getting-started)
- [Uglify Documentation](https://github.com/gruntjs/grunt-contrib-uglify)
- [Grunt Watch](https://github.com/gruntjs/grunt-contrib-watch)

## Version History

- **v1.0.0** (2025-10-20) - Initial Grunt setup with uglify and watch tasks
