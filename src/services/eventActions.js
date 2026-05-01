function hasOpenBlockers(blockers = []) {
  return blockers.some((blocker) => ['open', 'waiting'].includes(blocker.status));
}

function getNextRecommendedAction(event, context = {}) {
  const blockers = context.blockers || [];
  const assets = context.assets || [];
  const settlement = context.settlement;
  const hasApprovedFlyer = assets.some((asset) => asset.asset_type === 'flyer' && asset.approval_status === 'approved');

  if (hasOpenBlockers(blockers)) return 'Resolve open blockers';
  if (event.status === 'proposed') return 'Confirm date, owner, and event type';
  if (event.status === 'hold') return 'Confirm event or release hold';
  if (event.status === 'confirmed' && !hasApprovedFlyer) return 'Upload or approve flyer';
  if (event.status === 'needs_assets') return 'Complete required assets';
  if (event.status === 'ready_to_announce' && !event.public_visibility) return 'Publish public event page';
  if (event.status === 'published' && !event.ticket_url && Number(event.ticket_price) > 0) return 'Add ticketing link';
  if (event.status === 'completed' && !settlement) return 'Complete settlement';
  return 'Review event details';
}

module.exports = { getNextRecommendedAction };
