const { query } = require('../config/db');

const User = {
  findByEmail(email) {
    return query('SELECT * FROM users WHERE email = ? LIMIT 1', [email]).then((rows) => rows[0]);
  },
  findAll() {
    return query('SELECT id, name, email, role FROM users ORDER BY name');
  },
  create(user) {
    return query('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)', [
      user.name,
      user.email,
      user.password_hash,
      user.role || 'viewer'
    ]);
  }
};

module.exports = User;
