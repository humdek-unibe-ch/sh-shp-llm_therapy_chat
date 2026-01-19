/**
 * Gulp Build Configuration for LLM Therapy Chat Plugin
 * ====================================================
 *
 * Build tasks for compiling and bundling assets:
 * - React component (UMD bundle via Vite)
 *
 * Tasks:
 * - `gulp react-build`: Build React component (UMD bundle)
 * - `gulp react-install`: Install React dependencies
 * - `gulp react-watch`: Watch React files for changes
 * - `gulp default`: Build React component
 *
 * Output Locations:
 * - React UMD bundle: ../js/ext/therapy-chat.umd.js
 * - React CSS: ../css/ext/therapy-chat.css
 */

const gulp = require('gulp');
const concat = require('gulp-concat');
const uglify = require('gulp-uglify');
const cleanCSS = require('gulp-clean-css');
const rename = require('gulp-rename');
const { exec } = require('child_process');
const path = require('path');
const fs = require('fs');

// Paths
const paths = {
  react: {
    src: path.join(__dirname, '../react'),
    output: path.join(__dirname, '../js/ext')
  }
};

/**
 * Install React dependencies
 * Run this first before building React component
 */
gulp.task('react-install', function(cb) {
  console.log('Installing React dependencies...');
  exec('npm install', { cwd: paths.react.src }, function(err, stdout, stderr) {
    if (stdout) console.log(stdout);
    if (stderr) console.error(stderr);
    if (err) {
      console.error('Failed to install React dependencies:', err);
    } else {
      console.log('React dependencies installed successfully.');
    }
    cb(err);
  });
});

/**
 * Build React component as UMD bundle
 * Output: ../js/ext/therapy-chat.umd.js and ../css/ext/therapy-chat.css
 * Note: CSS files are automatically moved to css/ext/ by the npm build script
 */
gulp.task('react-build', function(cb) {
  console.log('Building React component...');

  // Use npm run build which runs tsc && vite build
  exec('npm run build', { cwd: paths.react.src }, function(err, stdout, stderr) {
    if (stdout) console.log(stdout);
    if (stderr) console.error(stderr);
    if (err) {
      console.error('React build failed:', err);
    } else {
      console.log('React component built successfully.');
      console.log('Output files:');
      console.log('  - js/ext/therapy-chat.umd.js');
      console.log('  - css/ext/therapy-chat.css');
    }
    cb(err);
  });
});

/**
 * Watch React files for changes during development
 * Uses Vite's built-in watch mode
 */
gulp.task('watch-react', function(cb) {
  console.log('Starting React watch mode...');
  exec('npm run watch', { cwd: paths.react.src }, function(err, stdout, stderr) {
    if (stdout) console.log(stdout);
    if (stderr) console.error(stderr);
    cb(err);
  });
});

/**
 * Legacy CSS build task (placeholder - no legacy CSS exists)
 * This plugin is fully React-based, so this task does nothing
 */
gulp.task('css', function(cb) {
  console.log('No legacy CSS to build - plugin is fully React-based.');
  cb();
});

/**
 * Legacy JS build task (placeholder - no legacy JS exists)
 * This plugin is fully React-based, so this task does nothing
 */
gulp.task('js', function(cb) {
  console.log('No legacy JS to build - plugin is fully React-based.');
  cb();
});

/**
 * Watch legacy files (placeholder - no legacy files exist)
 */
gulp.task('watch', function(cb) {
  console.log('No legacy files to watch - plugin is fully React-based.');
  cb();
});

/**
 * Full build task
 * Builds React component (no legacy assets to build)
 */
gulp.task('build', gulp.series('react-build'));

/**
 * Default task
 * Runs the React build
 */
gulp.task('default', gulp.series('build'));

/**
 * Clean task
 * Removes built React files
 */
gulp.task('clean', function(cb) {
  const del = require('del');
  del([
    paths.react.output + '/therapy-chat.umd.js',
    path.join(__dirname, '../css/ext/therapy-chat.css')
  ]).then(() => {
    console.log('Cleaned React build files.');
    cb();
  }).catch(cb);
});

/**
 * Help task
 * Displays available tasks
 */
gulp.task('help', function(cb) {
  console.log(`
LLM Therapy Chat Plugin - Gulp Tasks
=====================================

Available tasks:

  gulp                  - Build React component (default)
  gulp build            - Build React component
  gulp react-install    - Install React dependencies
  gulp react-build      - Build React component only (CSS auto-moved)
  gulp react-watch      - Watch React files with hot reload
  gulp clean            - Remove built files
  gulp help             - Show this help

  Legacy tasks (not used - plugin is fully React-based):
  gulp css              - No legacy CSS to build
  gulp js               - No legacy JS to build
  gulp watch            - No legacy files to watch

First-time setup:
  1. cd gulp
  2. npm install
  3. gulp react-install
  4. gulp build

Output locations:
  - React JS: js/ext/therapy-chat.umd.js
  - React CSS: css/ext/therapy-chat.css (auto-moved during build)

Development:
  gulp react-watch      - For React development with hot reload

Note: React CSS files are automatically moved to css/ext/ during the build process.
This plugin is fully React-based and does not have legacy CSS/JS assets.
`);
  cb();
});