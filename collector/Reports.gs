function esmRows_(site,collection,stale) {
  const snapshot=collection && collection.snapshot;
  let rows=snapshot?snapshot.statusRows.map(r=>Object.assign({},r)):[{id:'monitor',label:'Site checks',status:'Unknown',detail:'No current collection',source:'collector',checkedUtc:null}];
  rows=rows.filter(r=>!['http','collector','repair'].includes(r.id));
  if (stale) rows=rows.map(r=>Object.assign({},r,{status:!['Disabled','Unknown'].includes(r.status)?'Stale':r.status,detail:r.detail+' (historical snapshot)'}));
  const http=collection && collection.http;
  rows.push({id:'http',label:'Public stylesheet URLs',status:stale?'Stale':(!http || !http.checked?'Unknown':(http.failed?'Attention':'Healthy')),
    detail:http?http.checked+' sampled; '+http.failed+' failed; HTTP 200 and CSS content type required':'No HTTP sample',source:'Google URL fetch',checkedUtc:collection?collection.receivedUtc:null});
  rows.push({id:'collector',label:'External collection',status:stale?'Stale':'Healthy',detail:'Snapshot stored in Drive; receipt acknowledgment '+(collection && collection.receiptAccepted?'confirmed':'not confirmed in this record'),source:'Google collector',checkedUtc:collection?collection.receivedUtc:null});
  return esmOrderRows_(rows);
}
// Keep Google-derived rows in the same groups as the WordPress dashboard.
function esmOrderRows_(rows) {
  const groups=[['files','http','metadata','cache','directory','collector','freshness','monitor'],
    ['compatibility','email','reports'],['versions','purge','incident','report_schedule','report_result']];
  const rank={Attention:0,Stale:1,Pending:2,Unknown:3,Disabled:4,Enabled:5,Healthy:5};
  const position=id=>{for(let g=0;g<groups.length;g++){const i=groups[g].indexOf(id);if(i>=0)return [g,i];}return [2,99];};
  return rows.sort((a,b)=>{
    const [ag,ai]=position(a.id),[bg,bi]=position(b.id);
    if(ag!==bg) return ag-bg;
    if(ag===0) { const severity=(rank[a.status]??6)-(rank[b.status]??6); if(severity) return severity; }
    return ai-bi;
  });
}
// Match the plugin's presentation without changing stored/API status values.
function esmBadge_(r) {
  if(r.status==='Attention' && ['files','metadata','directory','http'].includes(r.id)) return '🔴 Failure';
  if(r.id==='versions' && r.status==='Unknown') return '🔵 Informational';
  const icons={Healthy:'🟢',Enabled:'🟢',Pending:'🟡',Attention:'🟠',Stale:'🟠',Disabled:'⚪',Unknown:'⚪',Recorded:'🔵'};
  return (icons[r.status]||'⚪')+' '+r.status;
}
function esmSummary_(rows) {
  const checks=rows.filter(r=>['files','metadata','cache','directory'].includes(r.id));
  if(checks.some(r=>esmBadge_(r).startsWith('🔴'))) return '🔴 Stylesheet checks failed';
  if(checks.some(r=>['Attention','Stale'].includes(r.status))) return '🟠 Stylesheet checks need attention';
  if(checks.some(r=>r.status==='Pending')) return '🟡 Awaiting stylesheet regeneration';
  if(!checks.length || checks.some(r=>r.status==='Unknown')) return '⚪ Stylesheet status unknown';
  return '🟢 Stylesheets healthy';
}
function esmPlain_(rows) { return [esmSummary_(rows),'STATUS | CHECK | DETAIL | SOURCE | CHECKED'].concat(rows.map(r=>[esmBadge_(r),r.label,r.detail,r.source,r.checkedUtc||'Not checked'].join(' | '))).join('\n'); }
function esmHtml_(value) { return String(value).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function esmTable_(rows) {
  return '<p><strong>'+esmHtml_(esmSummary_(rows))+'</strong></p><table border="1" cellpadding="7" cellspacing="0" style="border-collapse:collapse"><tr><th>Status</th><th>Check</th><th>Detail</th><th>Source</th><th>Checked (UTC)</th></tr>'+rows.map(r=>'<tr>'+[esmBadge_(r),r.label,r.detail,r.source,r.checkedUtc||'Not checked'].map(v=>'<td>'+esmHtml_(v)+'</td>').join('')+'</tr>').join('')+'</table>';
}
function esmSend_(site,subject,rows,notes) {
  MailApp.sendEmail({to:site.recipient,subject:subject,body:site.site+'\n\n'+esmPlain_(rows)+'\n\n'+notes,
    htmlBody:'<p>'+esmHtml_(site.label)+' — '+esmHtml_(site.site)+'</p>'+esmTable_(rows)+'<p>'+esmHtml_(notes).replace(/\n/g,'<br>')+'</p>'});
}
function esmAlarm_(site,state,collection,failed) {
  const health=collection && collection.snapshot.cssHealth;
  const pending=health && health.status==='awaiting_regeneration';
  // Public cached 404s remain actionable even while origin regeneration is pending.
  const httpFailed=collection && collection.http.failed>0;
  if (pending && !httpFailed && !failed) return;
  const bad=failed || !health || health.status!=='healthy' || httpFailed;
  const now=Date.now();
  if (!bad && !state.incident) return;
  if (bad && state.incident && now-(state.lastAlarm||0)<86400000) return;
  if (now-(state.lastAttempt||0)<300000) return;
  state.lastAttempt=now;
  esmSaveState_(site,state); // Reserve attempt before sending; retries remain bounded.
  const cacheOnly=health && health.issues && health.issues.length && health.issues.every(x=>['stale_individual_cache','cache_database_mismatch'].includes(x)) && !httpFailed;
  const reason=!bad?'checks recovered':failed?'collection unavailable':cacheOnly?'CSS cache metadata needs attention':'stylesheet health needs attention';
  const rows=esmRows_(site,collection,failed);
  esmSend_(site,(bad?'[ALARM] ':'[RECOVERY] ')+site.label+' — '+reason,rows,
    'Detected '+new Date().toISOString()+'. Inspect the WordPress monitoring page and relevant public pages. Collection is hourly; plugin alarms are independent.');
  state.incident=bad;
  state.lastAlarm=now;
  state.lastMailAcceptedUtc=new Date().toISOString();
  esmSaveState_(site,state);
}
function esmReportSite_(site,state,p,now) {
        const today=Utilities.formatDate(now,site.zone,'yyyy-MM-dd');
        const requested=state.reportThrough?Date.parse(state.reportThrough):now.getTime()-(p.frequency==='weekly'?7:1)*86400000;
        const start=new Date(Math.max(requested,now.getTime()-7*86400000));
        const folder=DriveApp.getFolderById(site.folderId),events=new Map(),collections=[];
        for (let day=new Date(Date.UTC(start.getUTCFullYear(),start.getUTCMonth(),start.getUTCDate()));day<=now;day=new Date(day.getTime()+86400000)) {
          const file=esmFile_(folder,'esm-'+site.id+'-'+Utilities.formatDate(day,'UTC','yyyy-MM-dd')+'.json');
          if (!file) continue;
          const journal=JSON.parse(file.getBlob().getDataAsString());
          if (journal.site!==site.site) throw new Error('Journal mismatch');
          for (const c of journal.collections) if (Date.parse(c.receivedUtc)<=now.getTime()) collections.push(c);
          for (const id of Object.keys(journal.events)) {
            const record=journal.events[id];
            if (Date.parse(record.firstSeenUtc)>start.getTime() && Date.parse(record.firstSeenUtc)<=now.getTime()) events.set(id,record.event);
          }
        }
        collections.sort((a,b)=>Date.parse(a.receivedUtc)-Date.parse(b.receivedUtc));
        const latest=collections.length?collections[collections.length-1]:null;
        const stale=!latest || now.getTime()-Date.parse(latest.receivedUtc)>7200000;
        const windowCollections=collections.filter(c=>Date.parse(c.receivedUtc)>start.getTime());
        const notes=['Window (UTC): '+start.toISOString()+' through '+now.toISOString(),
          'Collections in window: '+windowCollections.length,
          'Unique purge records first collected in window: '+events.size,
          'Possible rolling-log gap: '+(windowCollections.some(c=>c.gapPossible)?'YES — inspect source records':'none detected'),
          requested<start.getTime()?'Report catch-up capped at seven days; inspect older journals.':'',
          ...Array.from(events.values()).sort((a,b)=>Date.parse(a.utc)-Date.parse(b.utc)).slice(-50).map(e=>e.utc+' — '+(e.context||'unknown')+' — '+(e.elementor||'?')+' / '+(e.pro||'?')),
          'A purge hook does not prove deletion. A clean collection is an observation, not proof of continuous availability. Mail submission does not verify inbox delivery.'].filter(Boolean).join('\n');
        // Reserve the calendar period before mail submission. A crash or ambiguous
        // mail error cannot cause an automatic duplicate in the same period.
        const period=esmReportPeriod_(p,now);
        state.reportReservation={period:period,attemptUtc:now.toISOString(),status:'uncertain'};
        state.reportAttempt=now.getTime();
        esmSaveState_(site,state);
        try {
          esmSend_(site,(p.frequency==='weekly'?'[WEEKLY] ':'[DAILY] ')+site.label+' — stylesheet status — '+today,esmRows_(site,latest,stale),notes);
        } catch(e) {
          state.reportReservation.status='failed'; esmSaveState_(site,state); throw e;
        }
        state.reportReservation.status='accepted';
        state.reportLastAcceptedUtc=now.toISOString();
        state.reportThrough=now.toISOString(); state.lastReportDate=today; esmSaveState_(site,state);
}
