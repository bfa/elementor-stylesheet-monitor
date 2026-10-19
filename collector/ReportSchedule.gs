/* Report scheduling v1. No secrets, destinations or automatic repair. */
function esmReportZone_(zone) {
  // WordPress also supports fixed-offset zones; Google expects GMT-prefixed offsets.
  return /^[+-]\d{2}:\d{2}$/.test(zone)?'GMT'+zone:zone;
}
function esmReportPreferences_(site,config,data) {
  const p=data.reportPreferences;
  if(p===undefined) {
    // Rolling upgrade: old plugins retain the configured daily schedule.
    return {schemaVersion:0,enabled:true,frequency:'daily',hour:config.reportHour??7,weekday:1,
      timezone:site.zone,recipient:site.recipient,revision:null};
  }
  if(!p || p.schemaVersion!==1 || typeof p.enabled!=='boolean' || !['daily','weekly'].includes(p.frequency)
      || !Number.isInteger(p.hour) || p.hour<0 || p.hour>23 || !Number.isInteger(p.weekday) || p.weekday<1 || p.weekday>7
      || typeof p.timezone!=='string' || p.timezone.length>80 || !/^[a-f0-9]{64}$/.test(p.revision||'')
      || typeof p.recipient!=='string' || p.recipient.length>254
      || (p.enabled && !/^[^\s@,]+@[^\s@,]+\.[^\s@,]+$/.test(p.recipient))) throw new Error('Invalid report preferences');
  Utilities.formatDate(new Date(),esmReportZone_(p.timezone),'yyyy-MM-dd');
  return Object.assign({},p);
}
function esmReportLocal_(p,now) {
  const zone=esmReportZone_(p.timezone),date=Utilities.formatDate(now,zone,'yyyy-MM-dd');
  const day=new Date(date+'T12:00:00Z'),weekday=day.getUTCDay()||7;
  const monday=new Date(day.getTime()-(weekday-1)*86400000).toISOString().slice(0,10);
  return {date:date,hour:Number(Utilities.formatDate(now,zone,'HH')),weekday:weekday,monday:monday};
}
function esmReportPeriod_(p,now) {
  const d=esmReportLocal_(p,now);
  return (p.frequency==='daily'?'daily:'+d.date:'weekly:'+d.monday);
}
function esmReportAlready_(p,state,now) {
  const d=esmReportLocal_(p,now),floor=p.frequency==='daily'?d.date:d.monday;
  if(state.lastReportDate && state.lastReportDate>=floor) return true;
  const r=state.reportReservation;
  if(r && typeof r.attemptUtc==='string') {
    const at=new Date(r.attemptUtc);
    if(Number.isFinite(at.getTime()) && esmReportLocal_(p,at).date>=floor) return true;
  }
  return false;
}
function esmReportDue_(p,state,now) {
  if(!p.enabled || esmReportAlready_(p,state,now)) return false;
  const d=esmReportLocal_(p,now);
  return d.hour>=p.hour && (p.frequency==='daily' || d.weekday===p.weekday);
}
function esmNextReport_(p,state,now) {
  if(!p.enabled) return null;
  if(esmReportDue_(p,state,now)) return now.toISOString();
  const local=esmReportLocal_(p,now),zone=esmReportZone_(p.timezone);
  for(let day=0;day<9;day++) {
    const date=new Date(Date.parse(local.date+'T12:00:00Z')+day*86400000).toISOString().slice(0,10);
    const wall=Date.parse(date+'T'+String(p.hour).padStart(2,'0')+':00:00Z');
    let t=wall;
    // Resolve a wall-clock time with timezone offsets, without minute-by-minute scans.
    for(let i=0;i<3;i++) {
      const offset=Utilities.formatDate(new Date(t),zone,'Z');
      const sign=offset[0]==='-'?-1:1,minutes=sign*(Number(offset.slice(1,3))*60+Number(offset.slice(3,5)));
      const resolved=wall-minutes*60000;
      if(resolved===t) break;
      t=resolved;
    }
    // Nonexistent spring-forward hours fall back to the next actual local hour.
    for(let bump=0;bump<3;bump++) {
      const candidate=new Date(t+bump*3600000),d=esmReportLocal_(p,candidate);
      if(candidate>now && d.date===date && esmReportDue_(p,state,candidate)) return candidate.toISOString();
    }
  }
  return null;
}
function esmReportReceipt_(site,p,state,now) {
  if(p.schemaVersion!==1) return;
  const r=state.reportReservation||{};
  const legacyAccepted=Number.isFinite(Date.parse(state.reportThrough||''))?state.reportThrough:null;
  const result=esmRequest_(site,'POST','/stylesheet-monitor/v1/report-receipt',{
    schemaVersion:1,revision:p.revision,checkedUtc:now.toISOString(),nextUtc:esmNextReport_(p,state,now),
    lastStatus:r.status||(legacyAccepted?'accepted':'none'),lastAttemptUtc:r.attemptUtc||legacyAccepted,lastAcceptedUtc:state.reportLastAcceptedUtc||legacyAccepted
  });
  if(result.ok!==true) throw new Error('Report acknowledgment failed');
}
function esmReportAll() {
  const config=esmConfig_(),lock=LockService.getScriptLock();
  if(!lock.tryLock(1000)) return;
  try {
    for(const input of config.sites) {
      let site,state,p;
      try {
        site=esmPrepareSite_(input,config,true); state=esmState_(site);
        // Never send using stale preferences: fetch them on every scheduled check.
        const data=esmRequest_(site,'GET','/stylesheet-monitor/v1/snapshot');
        esmValidate_(site,data);
        p=esmReportPreferences_(site,config,data);
        site.zone=esmReportZone_(p.timezone); site.recipient=p.recipient;
        if(esmReportDue_(p,state,new Date())) esmReportSite_(site,state,p,new Date());
      } catch(e) { console.warn('Report check/submission could not complete for '+input.id+'; inspect configuration and execution history.'); }
      if(site && state && p) {
        try { esmReportReceipt_(site,p,state,new Date()); }
        catch(e) { console.warn('Report receipt could not complete for '+input.id); }
      }
    }
  } finally { lock.releaseLock(); }
}
// Safe status synchronization: no report, alarm or schedule changes.
function esmSyncReportSettings() {
  const config=esmConfig_(),lock=LockService.getScriptLock();
  if(!lock.tryLock(1000)) throw new Error('Collector busy');
  try {
    for(const input of config.sites) {
      const site=esmPrepareSite_(input,config,true),state=esmState_(site);
      const data=esmRequest_(site,'GET','/stylesheet-monitor/v1/snapshot'); esmValidate_(site,data);
      const p=esmReportPreferences_(site,config,data);
      esmReportReceipt_(site,p,state,new Date());
      console.log(site.id+': report settings '+(p.enabled?'enabled':'disabled')+', '+p.frequency+'; acknowledgment completed.');
    }
  } finally { lock.releaseLock(); }
}
// Adds one hourly report scheduler. Existing daily trigger is harmless because
// calendar-period reservations deduplicate it. Remove the old daily trigger in UI.
function esmInstallHourlyReports() {
  const props=PropertiesService.getScriptProperties(),saved=props.getProperty('ESM_REPORT_HOURLY_TRIGGER');
  if(saved && ScriptApp.getProjectTriggers().some(t=>t.getUniqueId()===saved)) {
    console.log('Hourly report scheduler already installed.'); return;
  }
  const t=ScriptApp.newTrigger('esmReportAll').timeBased().everyHours(1).create();
  props.setProperty('ESM_REPORT_HOURLY_TRIGGER',t.getUniqueId());
  console.log('Hourly report scheduler installed; collection trigger unchanged.');
}
function esmInstallTriggers() {
  const config=esmConfig_(); config.sites.forEach(s=>esmPrepareSite_(s,config,true));
  if(ScriptApp.getProjectTriggers().some(t=>['esmCollectAll','esmReportAll'].includes(t.getHandlerFunction()))) throw new Error('ESM triggers already present');
  ScriptApp.newTrigger('esmCollectAll').timeBased().everyHours(1).create();
  esmInstallHourlyReports();
}
function esmRemoveTriggers() {
  for(const t of ScriptApp.getProjectTriggers()) if(['esmCollectAll','esmReportAll'].includes(t.getHandlerFunction())) ScriptApp.deleteTrigger(t);
}
