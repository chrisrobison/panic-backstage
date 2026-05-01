function requireAuth(req, res, next) {
  if (!req.session.user) return res.redirect('/login');
  return next();
}

function attachUser(req, res, next) {
  res.locals.currentUser = req.session.user || null;
  res.locals.currentPath = req.path;
  res.locals.flash = {
    error: req.flash('error'),
    success: req.flash('success')
  };
  res.locals.csrfToken = req.csrfToken ? req.csrfToken() : '';
  next();
}

module.exports = { requireAuth, attachUser };
