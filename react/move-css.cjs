/**
 * Move CSS file to correct location after build
 */
const fs = require('fs');
const path = require('path');

const srcCss = path.join(__dirname, '..', 'js', 'ext', 'therapy-chat.css');
const destCss = path.join(__dirname, '..', 'css', 'ext', 'therapy-chat.css');

// Ensure css/ext directory exists
const cssDir = path.join(__dirname, '..', 'css', 'ext');
if (!fs.existsSync(cssDir)) {
  fs.mkdirSync(cssDir, { recursive: true });
}

// Move CSS file if it exists
if (fs.existsSync(srcCss)) {
  fs.renameSync(srcCss, destCss);
  console.log('Moved therapy-chat.css to css/ext/');
} else {
  console.log('No CSS file to move');
}
