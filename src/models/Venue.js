const { query } = require('../config/db');

module.exports = {
  findAll() {
    return query('SELECT * FROM venues ORDER BY name');
  }
};
