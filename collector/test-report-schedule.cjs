const fs=require('fs'),vm=require('vm'),assert=require('assert');
function formatDate(date,zone,pattern){
 const fixed=/^GMT([+-])(\d{2}):(\d{2})$/.exec(zone);
 if(fixed){date=new Date(date.getTime()+(fixed[1]==='-'?-1:1)*(+fixed[2]*60+ +fixed[3])*60000);zone='UTC';}
 const parts=Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',hourCycle:'h23',minute:'2-digit'}).formatToParts(date).map(p=>[p.type,p.value]));
 if(pattern==='yyyy-MM-dd')return `${parts.year}-${parts.month}-${parts.day}`;
 if(pattern==='HH')return parts.hour;
 if(pattern==='Z'){
  if(fixed)return fixed[1]+fixed[2]+fixed[3];
  const local=Date.UTC(+parts.year,+parts.month-1,+parts.day,+parts.hour,+parts.minute),delta=Math.round((local-Math.floor(date.getTime()/60000)*60000)/60000);
  return (delta<0?'-':'+')+String(Math.floor(Math.abs(delta)/60)).padStart(2,'0')+String(Math.abs(delta)%60).padStart(2,'0');
 }
 throw Error(pattern);
}
let stored={},sent=0,failMail=false;
const c={Date,console,Utilities:{formatDate},esmSaveState_:(s,state)=>{stored=JSON.parse(JSON.stringify(state));},
 esmFile_:()=>null,DriveApp:{getFolderById:()=>({})},esmRows_:()=>[],esmSend_:()=>{sent++;if(failMail)throw Error('ambiguous');}};
vm.createContext(c);for(const f of ['Reports.gs','ReportSchedule.gs'])vm.runInContext(fs.readFileSync(__dirname+'/'+f,'utf8'),c);
// Override presentation sender after source load; no actual mail/network/storage.
c.esmSend_=()=>{sent++;if(failMail)throw Error('ambiguous');};c.esmRows_=()=>[];
const p={enabled:true,frequency:'daily',hour:7,weekday:1,timezone:'America/New_York',recipient:'test@example.org'};
const at=s=>new Date(s),site={id:'test',site:'https://example.org',zone:'America/New_York'};
assert(!c.esmReportDue_({...p,enabled:false},{},at('2026-10-19T12:00:00Z')));
assert(!c.esmReportDue_(p,{},at('2026-10-19T10:59:00Z')));
assert(c.esmReportDue_(p,{},at('2026-10-19T11:31:00Z')));
assert(!c.esmReportDue_(p,{lastReportDate:'2026-10-19'},at('2026-10-19T12:00:00Z')));
assert.equal(c.esmNextReport_(p,{lastReportDate:'2026-10-19'},at('2026-10-19T12:00:00Z')),'2026-10-20T11:00:00.000Z');
const weekly={...p,frequency:'weekly'};
assert(c.esmReportDue_(weekly,{},at('2026-10-19T11:30:00Z')));
assert(!c.esmReportDue_(weekly,{lastReportDate:'2026-10-19'},at('2026-10-19T12:00:00Z')));
assert(!c.esmReportDue_(weekly,{},at('2026-10-20T12:00:00Z')));
assert.equal(c.esmNextReport_(weekly,{lastReportDate:'2026-10-19'},at('2026-10-19T12:00:00Z')),'2026-10-26T11:00:00.000Z');
assert.equal(c.esmNextReport_({...p,timezone:'+05:30'}, {},at('2026-10-19T00:00:00Z')),'2026-10-19T01:30:00.000Z');
assert.equal(c.esmNextReport_(p,{lastReportDate:'2026-10-31'},at('2026-10-31T15:00:00Z')),'2026-11-01T12:00:00.000Z');
assert.equal(c.esmNextReport_({...p,hour:2},{lastReportDate:'2027-03-13'},at('2027-03-13T15:00:00Z')),'2027-03-14T07:00:00.000Z');
const runTime=at('2026-10-19T11:30:00Z'),state={};
c.esmReportSite_(site,state,p,runTime);assert.equal(sent,1);assert.equal(stored.reportReservation.status,'accepted');
assert(!c.esmReportDue_({...p,hour:10},stored,at('2026-10-19T15:00:00Z')));
assert(!c.esmReportDue_(weekly,stored,at('2026-10-19T15:00:00Z')));
failMail=true;assert.throws(()=>c.esmReportSite_(site,{},p,runTime));assert.equal(stored.reportReservation.status,'failed');
assert(!c.esmReportDue_(p,stored,at('2026-10-19T15:00:00Z')));
const prefs={schemaVersion:1,...p,revision:'a'.repeat(64)};
assert.equal(c.esmReportPreferences_(site,{}, {reportPreferences:prefs}).recipient,'test@example.org');
assert.throws(()=>c.esmReportPreferences_(site,{}, {reportPreferences:{...prefs,recipient:'a@example.org,b@example.org'}}));
assert.equal(c.esmReportPreferences_(site,{reportHour:8},{}).hour,8);
let reportNotes=''; c.esmSend_=(site,subject,rows,notes)=>{reportNotes=notes;};
c.esmReportSite_(site,{reportThrough:'2026-10-18T11:31:00.000Z'},weekly,runTime);
assert(reportNotes.includes('2026-10-12T11:30:00.000Z through 2026-10-19T11:30:00.000Z'));
console.log('PASS: disabled/daily/weekly, migration dedupe, schedule edits, fixed timezone/DST, next windows, shared recipient validation, durable failure reservation; no real services');
