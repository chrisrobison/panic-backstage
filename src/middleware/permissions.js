const { getEventRole, can, canEditEventRole } = require('../services/permissionsService');

async function requireEventEdit(req, res, next) {
  const role = await getEventRole(req.session.user, req.params.id);
  if (!canEditEventRole(role)) {
    req.flash('error', 'You do not have permission to edit this event.');
    return res.redirect(`/events/${req.params.id}`);
  }
  res.locals.eventRole = role;
  return next();
}

async function attachEventRole(req, res, next) {
  if (req.params.id && req.session.user) {
    res.locals.eventRole = await getEventRole(req.session.user, req.params.id);
  }
  next();
}

function requireEventCapability(capability) {
  return async function eventCapability(req, res, next) {
    const role = await getEventRole(req.session.user, req.params.id);
    if (!can(role, capability)) {
      req.flash('error', 'You do not have permission for that event action.');
      return res.redirect(`/events/${req.params.id}`);
    }
    res.locals.eventRole = role;
    return next();
  };
}

function requireSettlementAccess(req, res, next) {
  const role = res.locals.eventRole;
  if (!['venue_admin', 'event_owner'].includes(role)) {
    req.flash('error', 'Settlement is limited to venue admins and event owners.');
    return res.redirect(`/events/${req.params.id}#settlement`);
  }
  return next();
}

module.exports = { requireEventEdit, attachEventRole, requireEventCapability, requireSettlementAccess };
