const path = require('path');
const express = require('express');
const session = require('express-session');
const flash = require('connect-flash');
const csrf = require('csurf');
const expressLayouts = require('express-ejs-layouts');
const methodOverride = require('method-override');
require('dotenv').config();

const { attachUser } = require('./middleware/auth');

const app = express();

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));
app.use(expressLayouts);
app.set('layout', 'layouts/main');

app.use(express.urlencoded({ extended: true }));
app.use(express.json());
app.use(methodOverride('_method'));
app.use('/public', express.static(path.join(__dirname, 'public')));
app.use('/uploads', express.static(path.join(__dirname, '..', 'uploads')));

app.use(
  session({
    secret: process.env.SESSION_SECRET || 'dev-secret-change-me',
    resave: false,
    saveUninitialized: false,
    cookie: { httpOnly: true, sameSite: 'lax' }
  })
);
app.use(flash());
app.use(csrf());
app.use(attachUser);

app.use('/', require('./routes/auth'));
app.use('/', require('./routes/public'));
app.use('/', require('./routes/invites'));
app.use('/dashboard', require('./routes/dashboard'));
app.use('/events', require('./routes/events'));
app.use('/templates', require('./routes/templates'));

app.get('/', (req, res) => res.redirect(req.session.user ? '/dashboard' : '/login'));

app.use((req, res) => {
  res.status(404).render('error', { title: 'Not found', message: 'Page not found.' });
});

app.use((err, req, res, next) => {
  console.error(err);
  if (err.code === 'EBADCSRFTOKEN') {
    req.flash('error', 'Your session form token expired. Please try again.');
    return res.redirect(req.get('referer') || '/dashboard');
  }
  res.status(500).render('error', { title: 'Server error', message: err.message || 'Something went wrong.' });
});

module.exports = app;
