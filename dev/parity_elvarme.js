// Helper for dev/test_elvarme_cost.php: runs the JS implementations (includes/app.js
// elvarmeCost and includes/pricing.js) on the cases in a JSON file and prints the
// results as JSON, so PHP can check that includes/ElvarmeCost.php agrees.
//   node dev/parity_elvarme.js cases.json
const fs = require('fs');
const path = require('path');
const P = require('../includes/pricing.js');

const appSrc = fs.readFileSync(path.join(__dirname, '../includes/app.js'), 'utf8');
const start = appSrc.indexOf('const elvarmeCost = (');
const m = /\r?\n\};\r?\n/.exec(appSrc.slice(start));
if (start < 0 || !m) { console.error('cannot extract elvarmeCost from app.js'); process.exit(2); }
const elvarmeCost = new Function(appSrc.slice(start, start + m.index + m[0].length) + '\nreturn elvarmeCost;')();

const cases = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const out = {
    cost: cases.cost.map(c => elvarmeCost(c)),
    priceByHour: cases.weight.map(c => P.priceByHour(c.high)),
    weightedRates: cases.weight.map(c => P.weightedRates(c.consumption, c.high, c.low)),
};
process.stdout.write(JSON.stringify(out));
