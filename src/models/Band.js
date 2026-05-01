const { query } = require('../config/db');

module.exports = {
  async findOrCreateByName(name) {
    const clean = String(name || '').trim();
    if (!clean) return null;
    const existing = await query('SELECT * FROM bands WHERE name = ? LIMIT 1', [clean]);
    if (existing[0]) return existing[0];
    const result = await query('INSERT INTO bands (name) VALUES (?)', [clean]);
    return { id: result.insertId, name: clean };
  }
};
