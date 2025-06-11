-- ic2carlx_mnps_students_compare.sql
-- James Staub
-- Nashville Public Library
-- Using shell instead of php as per https://stackoverflow.com/questions/35999597/importing-csv-file-into-sqlite3-from-php#36001304
-- TO DO: bug in SQLite 3.7.17 forces us to create the table before importing data into it. See https://stackoverflow.com/questions/38035543/sqlite3-import-csv-not-working
-- TO DO: update SQLite to version 3.24.0 (2018-06-04) to get UPSERT https://sqlite.org/lang_UPSERT.html
-- TO DO: determine the benefit and method of using prepare()
-- TO DO: for patron data privacy, kill this database when actions are complete

DROP TABLE IF EXISTS carlx;
-- CREATE TABLE carlx (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryStreetAddress,SecondaryCity,SecondaryState,SecondaryZipCode,PrimaryPhoneNumber,SecondaryPhoneNumber,AlternateID,NonvalidatedStats,DefaultBranch,ValidatedStatCodes,StatusCode,RegistrationDate,LastActionDate,ExpirationDate,EmailAddress,Notes,BirthDate,Guarantor,RacialorEthnicCategory,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName);
CREATE TABLE carlx (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryPhoneNumber,DefaultBranch,ExpirationDate,EmailAddress,BirthDate,Guarantor,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName,EmailNotices,ExpiredNoteIDs,DeleteGuarantorNoteIDs,CollectionStatus,EditBranch,PrimaryPhoneNumber);

DROP TABLE IF EXISTS infinitecampus;
-- CREATE TABLE infinitecampus (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryStreetAddress,SecondaryCity,SecondaryState,SecondaryZipCode,PrimaryPhoneNumber,SecondaryPhoneNumber,AlternateID,NonvalidatedStats,DefaultBranch,ValidatedStatCodes,StatusCode,RegistrationDate,LastActionDate,ExpirationDate,EmailAddress,Notes,BirthDate,Guarantor,RacialorEthnicCategory,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName);
CREATE TABLE infinitecampus (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryPhoneNumber,DefaultBranch,ExpirationDate,EmailAddress,BirthDate,Guarantor,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName,EmailNotices,ExpiredNoteIDs,DeleteGuarantorNoteIDs,CollectionStatus,EditBranch,PrimaryPhoneNumber);

.headers on
.mode csv
.import ../data/ic2carlx_mnps_students_carlx.csv carlx
.import ../data/ic2carlx_mnps_students_infinitecampus.csv infinitecampus

-- UPDATE PATRON SEEN
create table if not exists patron_seen (patronid,patron_seen);

insert into patron_seen (patronid,patron_seen)
select carlx.patronid, null
from carlx
left join patron_seen on carlx.patronid = patron_seen.patronid
where patron_seen.patronid is null;

update patron_seen
set patron_seen = CURRENT_DATE
where patronid in (
	select i.patronid 
	from infinitecampus i 
	inner join patron_seen p on i.patronid = p.patronid
); 

-- INSERT PATRON SEEN
insert into patron_seen 
select i.patronid, CURRENT_DATE 
from infinitecampus i 
left join patron_seen p on i.patronid = p.patronid 
where p.patronid is null;

-- "REMOVE" CARLX PATRON
-- Skip this section if processing promising scholars -- This comment is used by ic2carlx_mnps.exp -- DO NOT ALTER
SELECT CASE WHEN 1 = 1 THEN ' -- Used by ic2carlx_mnps.exp -- DO NOT ALTER
drop table if exists carlx_remove;
create table if not exists carlx_remove (patronid,patron_seen,emailaddress,collectionstatus,defaultbranch,borrowertypecode,primaryphonenumber,secondaryphonenumber,teacherid,teachername);
delete
from carlx_remove
;
insert into carlx_remove select distinct p.patronid,
	p.patron_seen,
	c.emailaddress,
	c.collectionstatus,
	c.defaultbranch,
	c.borrowertypecode,
	c.primaryphonenumber,
	c.secondaryphonenumber,
    c.teacherid,
    c.teachername
from patron_seen p
left join carlx c on p.patronid = c.PatronID
where c.editbranch != 'XMNPS'
and (patron_seen < date('now','-7 days') or patron_seen is null)
order by p.patronid
;
.headers on
.output ../data/ic2carlx_mnps_students_remove.csv
select * from carlx_remove;
.output stdout

delete
from patron_seen
where patron_seen < date('now','-7 days')
or patron_seen is null
;
' ELSE '' END AS sql_to_execute;

-- Execute the dynamic SQL
.print @sql_to_execute

-- CREATE CARLX PATRON
-- drop table if exists carlx_create;
create table if not exists carlx_create (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryPhoneNumber,DefaultBranch,ExpirationDate,EmailAddress,BirthDate,Guarantor,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName,EmailNotices,ExpiredNoteIDs,DeleteGuarantorNoteIDs,CollectionStatus,EditBranch,PrimaryPhoneNumber);
delete 
from carlx_create
;
insert into carlx_create select infinitecampus.PatronID,
	infinitecampus.Borrowertypecode,
	infinitecampus.Patronlastname,
	infinitecampus.Patronfirstname,
	infinitecampus.Patronmiddlename,
	infinitecampus.Patronsuffix,
	infinitecampus.PrimaryStreetAddress,
	infinitecampus.PrimaryCity,
	infinitecampus.PrimaryState,
	infinitecampus.PrimaryZipCode,
	infinitecampus.SecondaryPhoneNumber,
	infinitecampus.DefaultBranch,
	infinitecampus.ExpirationDate,
	infinitecampus.EmailAddress,
	infinitecampus.BirthDate,
	infinitecampus.Guarantor,
	infinitecampus.LapTopCheckOut,
	infinitecampus.LimitlessLibraryUse,
	infinitecampus.TechOptOut,
	infinitecampus.TeacherID,
	infinitecampus.TeacherName,
	infinitecampus.EmailNotices,
	infinitecampus.ExpiredNoteIDs,
	infinitecampus.DeleteGuarantorNoteIDs,
	infinitecampus.CollectionStatus,
	infinitecampus.EditBranch,
	infinitecampus.PrimaryPhoneNumber
from infinitecampus
left join carlx on infinitecampus.PatronID = carlx.PatronID
where carlx.PatronID IS NULL
order by infinitecampus.PatronID
;
.headers on
.output ../data/ic2carlx_mnps_students_create.csv
select * from carlx_create;
.output stdout

-- UPDATE CARLX PATRON (IGNORE EMAIL; IGNORE GUARANTOR; IGNORE UDF VALUES)
create table if not exists carlx_update (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryPhoneNumber,DefaultBranch,ExpirationDate,BirthDate,TeacherID,TeacherName,CollectionStatus);
delete 
from carlx_update
;
insert into carlx_update select PatronID,
	Borrowertypecode,
	Patronlastname,
	Patronfirstname,
	Patronmiddlename,
	Patronsuffix,
	PrimaryStreetAddress,
	PrimaryCity,
	PrimaryState,
	PrimaryZipCode,
	SecondaryPhoneNumber,
	DefaultBranch,
	ExpirationDate,
	BirthDate,
	TeacherID,
	TeacherName,
	CollectionStatus
from infinitecampus
except
select carlx.PatronID,
	carlx.Borrowertypecode,
	carlx.Patronlastname,
	carlx.Patronfirstname,
	carlx.Patronmiddlename,
	carlx.Patronsuffix,
	carlx.PrimaryStreetAddress,
	carlx.PrimaryCity,
	carlx.PrimaryState,
	carlx.PrimaryZipCode,
	carlx.SecondaryPhoneNumber,
	carlx.DefaultBranch,
	carlx.ExpirationDate,
	carlx.BirthDate,
	carlx.TeacherID,
	carlx.TeacherName,
	carlx.CollectionStatus
from carlx
except
	select 	infinitecampus.PatronID,
		infinitecampus.Borrowertypecode,
		infinitecampus.Patronlastname,
		infinitecampus.Patronfirstname,
		infinitecampus.Patronmiddlename,
		infinitecampus.Patronsuffix,
		infinitecampus.PrimaryStreetAddress,
		infinitecampus.PrimaryCity,
		infinitecampus.PrimaryState,
		infinitecampus.PrimaryZipCode,
		infinitecampus.SecondaryPhoneNumber,
		infinitecampus.DefaultBranch,
		infinitecampus.ExpirationDate,
		infinitecampus.BirthDate,
		infinitecampus.TeacherID,
		infinitecampus.TeacherName,
		infinitecampus.CollectionStatus
	from infinitecampus
	left join carlx on infinitecampus.PatronID = carlx.PatronID
	where carlx.PatronID IS NULL
	order by infinitecampus.PatronID
;
.headers on
.output ../data/ic2carlx_mnps_students_update.csv
select * from carlx_update;
.output stdout

-- EMAIL
.headers on
.output ../data/ic2carlx_mnps_students_updateEmail.csv
select i.PatronID as PatronID,
	i.EmailAddress as Email,
	'send email' as EmailNotices
from infinitecampus i
left join carlx c on i.PatronID = c.PatronID
where (
	(c.EmailAddress like '%mnpsk12.org' and c.EmailNotices != '1') 
	or (c.EmailAddress != '' and c.EmailAddress not like '%mnpsk12.org' and c.EmailNotices in ('0','3')) 
	or (c.EmailAddress = '' and i.EmailAddress = '' and c.EmailNotices != '1') 
	or (c.EmailAddress = '' and i.EmailAddress != '')
)
;
.headers off
select c.PatronID as PatronID,
        c.EmailAddress as Email,
        'send email' as EmailNotices
from infinitecampus i
left join carlx c on i.PatronID = c.PatronID
where c.EmailAddress != ''
	and c.EmailAddress not like '%mnpsk12.org'
	and c.EmailNotices = '2'
order by c.PatronID
;
.output stdout

-- GUARANTOR (ADD GUARANTOR NOTE)
.headers on
.output ../data/ic2carlx_mnps_students_createNoteGuarantor.csv
select i.PatronID, 
	'NPL: MNPS Guarantor effective ' || max(CURRENT_DATE,'2024-08-06') || ' - ' || upper(i.Guarantor) as Guarantor,
	i.ExpirationDate
from infinitecampus i
left join carlx c on i.PatronID = c.PatronID
where upper(i.Guarantor) != upper(c.Guarantor)
and i.Guarantor != ''
and i.Guarantor is not null
order by c.PatronID
;
.output stdout


-- CreatePatronUserDefinedFields
/* DISABLED 2019 05 17
.headers off
.output ../data/ic2carlx_mnps_students_createUdf.csv
select 'patronid',
	'occur',
	'fieldid',
	'numcode',
	'type',
	'valuename';
-- CreatePatronUserDefinedFields UDF1 TechOptOut 
.headers off
select i.* 
from (
        select infinitecampus.PatronID as patronid, 
		'0' as occur, 
		'1' as fieldid, 
		case when infinitecampus.TechOptOut = 'Yes' then '1' else '2' end as numcode, 
		'0' as type, 
		infinitecampus.TechOptOut as valuename
        from infinitecampus
	where valuename in ('Yes','No')
) i left join (
        select carlx.PatronID, carlx.TechOptOut
        from carlx
	where carlx.TechOptOut in ('Yes','No')
) c on i.PatronID = c.PatronID
where c.PatronID IS NULL
order by i.PatronID
;
-- CreatePatronUserDefinedFields UDF3 LapTopCheckOut 
.headers off
select i.* 
from (
        select infinitecampus.PatronID as patronid, 
		'0' as occur, 
		'3' as fieldid, 
		case when infinitecampus.LapTopCheckOut = 'Yes' then '1' else '2' end as numcode, 
		'0' as type, 
		infinitecampus.LapTopCheckOut as valuename
        from infinitecampus
	where valuename in ('Yes','No')
) i left join (
        select carlx.PatronID, carlx.LapTopCheckOut
        from carlx
	where carlx.LapTopCheckOut in ('Yes','No')
) c on i.PatronID = c.PatronID
where c.PatronID IS NULL
order by i.PatronID
;
-- CreatePatronUserDefinedFields UDF4 LimitlessLibraryUse
.headers off
select i.* 
from (
        select infinitecampus.PatronID as patronid, 
		'0' as occur, 
		'4' as fieldid, 
		case when infinitecampus.LimitlessLibraryUse = 'No' then '2' else '1' end as numcode, 
		'0' as type, 
		case when infinitecampus.LimitlessLibraryUse = 'No' then 'No' else 'Yes' end as valuename 
        from infinitecampus
	where valuename in ('Yes','No','')
) i left join (
        select carlx.PatronID, carlx.LimitlessLibraryUse
        from carlx
	where carlx.LimitlessLibraryUse != ''
) c on i.PatronID = c.PatronID
where c.PatronID IS NULL
order by i.PatronID
;
.output stdout

-- UpdatePatronUserDefinedFields
.headers off
.output ../data/ic2carlx_mnps_students_updateUdf.csv
select 'new_patronid',
	'new_occur',
	'new_fieldid',
	'new_numcode',
	'new_type',
	'new_valuename',
	'old_patronid',
	'old_occur',
	'old_fieldid',
	'old_numcode',
	'old_type',
	'old_valuename';
-- UpdatePatronUserDefinedFields UDF1 TechOptOut
.headers off
select *
from (
        select infinitecampus.PatronID as new_patronid,
                '0' as new_occur,
                '1' as new_fieldid,
                case when infinitecampus.TechOptOut = 'Yes' then '1' else '2' end as new_numcode,
                '0' as new_type,
                infinitecampus.TechOptOut as new_valuename
        from infinitecampus
	where new_valuename is not null
	and new_valuename != ''
) i left join (
        select carlx.PatronID as old_patronid,
        '0' as old_occur,
        '1' as old_fieldid,
        case when carlx.TechOptOut = 'Yes' then '1' else '2' end as old_numcode,
        '0' as old_type,
        carlx.TechOptOut as old_valuename
        from carlx
        where carlx.TechOptOut != ''
) c on i.new_patronid = c.old_patronid
where c.old_patronid IS NOT NULL
and i.new_numcode != c.old_numcode
order by i.new_patronid
;
-- UpdatePatronUserDefinedFields UDF3 LapTopCheckOut
.headers off
select *
from (
        select infinitecampus.PatronID as new_patronid,
                '0' as new_occur,
                '3' as new_fieldid,
                case when infinitecampus.LapTopCheckOut = 'Yes' then '1' else '2' end as new_numcode,
                '0' as new_type,
                infinitecampus.LapTopCheckOut as new_valuename
        from infinitecampus
	where new_valuename is not null
	and new_valuename != ''
) i left join (
        select carlx.PatronID as old_patronid,
        	'0' as old_occur,
        	'3' as old_fieldid,
        	case when carlx.LapTopCheckOut = 'Yes' then '1' else '2' end as old_numcode,
        	'0' as old_type,
        	carlx.LapTopCheckOut as old_valuename
        from carlx
        where carlx.LapTopCheckOut != ''
) c on i.new_patronid = c.old_patronid
where c.old_patronid IS NOT NULL
and i.new_numcode != c.old_numcode
order by i.new_patronid
;
-- UpdatePatronUserDefinedFields UDF4 LimitlessLibraryUse
.headers off
select *
from (
        select infinitecampus.PatronID as new_patronid,
                '0' as new_occur,
                '4' as new_fieldid,
                case when infinitecampus.LimitlessLibraryUse = 'Yes' then '1' else '2' end as new_numcode,
                '0' as new_type,
                infinitecampus.LimitlessLibraryUse as new_valuename
        from infinitecampus
	where new_valuename is not null
	and new_valuename != ''
) i left join (
        select carlx.PatronID as old_patronid,
        	'0' as old_occur,
        	'4' as old_fieldid,
        	case when carlx.LimitlessLibraryUse = 'Yes' then '1' else '2' end as old_numcode,
        	'0' as old_type,
        	carlx.LimitlessLibraryUse as old_valuename
        from carlx
        where carlx.LimitlessLibraryUse != ''
) c on i.new_patronid = c.old_patronid
where c.old_patronid IS NOT NULL
and i.new_numcode != c.old_numcode
order by i.new_patronid
;
.output stdout
*/

-- Delete Expired MNPS Patron Notes when patron re-appears in Infinite Campus
.headers on
.output ../data/ic2carlx_mnps_students_deleteExpiredNotes.csv
select c.PatronID, 
	c.ExpiredNoteIDs 
from infinitecampus i 
inner join carlx c on i.patronid = c.patronid 
where c.ExpiredNoteIDs != ""
order by c.PatronID
;
.output stdout

-- DELETE GUARANTOR NOTES WHEN APPROPRIATE
.headers on
.output ../data/ic2carlx_mnps_students_deleteGuarantorNotes.csv
select c.PatronID, 
	c.DeleteGuarantorNoteIDs
from carlx c
where c.DeleteGuarantorNoteIDs != ""
order by c.PatronID
;
.output stdout

-- REPORT x BRANCH
create table if not exists report_defaultbranch (date,defaultbranch,carlx,infinitecampus,created,updated,removed,cx_ll_yes,ic_ll_yes,cx_ll_no,ic_ll_no);
delete from report_defaultbranch
where date = CURRENT_DATE;
insert into report_defaultbranch 
select CURRENT_DATE as date, 
	x.defaultbranch,
	ifnull(carlx,0),
	ifnull(infinitecampus,0),
	ifnull(created,0),
	ifnull(updated,0),
	ifnull(removed,0),
	ifnull(cx_ll_yes,0),
	ifnull(ic_ll_yes,0),
	ifnull(cx_ll_no,0),
	ifnull(ic_ll_no,0)
from (
	select defaultbranch,
	count(patronid) as carlx
	from carlx
	group by defaultbranch
	order by defaultbranch
) x 
left outer join (
	select defaultbranch,
	count(patronid) as infinitecampus
	from infinitecampus
	group by defaultbranch
	order by defaultbranch
) i on x.defaultbranch = i.defaultbranch
left outer join (
	select defaultbranch, 
	count(patronid) as created
	from carlx_create 
	group by defaultbranch
	order by defaultbranch
) c on x.defaultbranch = c.defaultbranch
left outer join (
	select defaultbranch, 
	count(patronid) as updated
	from carlx_update 
	group by defaultbranch
	order by defaultbranch
) u on x.defaultbranch = u.defaultbranch
left outer join (
	select defaultbranch, 
	count(patronid) as removed
	from carlx_remove 
	group by defaultbranch
	order by defaultbranch
) r on x.defaultbranch = r.defaultbranch
left outer join (
	select defaultbranch, 
	count(patronid) as cx_ll_yes
	from carlx
	where (borrowertypecode between 21 and 34 or borrowertypecode = 46)
	group by defaultbranch 
	order by defaultbranch
) x1 on x.defaultbranch = x1.defaultbranch
left outer join (
	select defaultbranch, 
	count(patronid) as ic_ll_yes
	from infinitecampus
	where limitlesslibraryuse = 'Yes' 
	group by defaultbranch 
	order by defaultbranch
) i1 on x.defaultbranch = i1.defaultbranch
left outer join (
	select defaultbranch, 
	count(patronid) as cx_ll_no
	from carlx
	where (borrowertypecode between 35 and 37 or borrowertypecode = 47)
	group by defaultbranch 
	order by defaultbranch
) x0 on x.defaultbranch = x0.defaultbranch
left outer join (
	select defaultbranch, 
	count(patronid) as ic_ll_no
	from infinitecampus
	where limitlesslibraryuse = 'No' 
	group by defaultbranch 
	order by defaultbranch
) i0 on x.defaultbranch = i0.defaultbranch
;
.headers on
.output ../data/ic2carlx_mnps_students_report_defaultbranch.csv
select defaultbranch,
	carlx,
	infinitecampus,
	created,
	updated,
	removed,
	cx_ll_yes,
	ic_ll_yes,
	cx_ll_no,
	ic_ll_no
from report_defaultbranch
where report_defaultbranch.date = CURRENT_DATE
;
.output stdout

-- REPORT x BRANCH : ABORT PATRON LOAD
.headers on
.output ../data/ic2carlx_mnps_students_report_defaultbranch_ABORT.csv
select *
from report_defaultbranch
where date = CURRENT_DATE
and defaultbranch != 'XMNPS'
and (infinitecampus <= carlx*.9
	or created >= carlx*.1
	or updated >= carlx*.1
	or removed >= carlx*.1
	or abs(cx_ll_yes - ic_ll_yes)/cx_ll_yes >= .1
)
;
.output stdout

-- REPORT x BORROWER TYPE
create table if not exists report_borrowertypecode (date,borrowertypecode,carlx,infinitecampus,created,updated,removed,cx_ll_yes,ic_ll_yes,cx_ll_no,ic_ll_no);
delete from report_borrowertypecode
where date = CURRENT_DATE;
insert into report_borrowertypecode
select CURRENT_DATE as date, 
	x.borrowertypecode,
	ifnull(carlx,0),
	ifnull(infinitecampus,0),
	ifnull(created,0),
	ifnull(updated,0),
	ifnull(removed,0),
	ifnull(cx_ll_yes,0),
	ifnull(ic_ll_yes,0),
	ifnull(cx_ll_no,0),
	ifnull(ic_ll_no,0)
from (
	select borrowertypecode,
	count(patronid) as carlx
	from carlx
	group by borrowertypecode
	order by borrowertypecode
) x 
left outer join (
	select borrowertypecode,
	count(patronid) as infinitecampus
	from infinitecampus
	group by borrowertypecode
	order by borrowertypecode
) i on x.borrowertypecode = i.borrowertypecode
left outer join (
	select borrowertypecode, 
	count(patronid) as created
	from carlx_create 
	group by borrowertypecode
	order by borrowertypecode
) c on x.borrowertypecode = c.borrowertypecode
left outer join (
	select borrowertypecode, 
	count(patronid) as updated
	from carlx_update 
	group by borrowertypecode
	order by borrowertypecode
) u on x.borrowertypecode = u.borrowertypecode
left outer join (
	select borrowertypecode, 
	count(patronid) as removed
	from carlx_remove 
	group by borrowertypecode
	order by borrowertypecode
) r on x.borrowertypecode = r.borrowertypecode
left outer join (
	select borrowertypecode, 
	count(patronid) as cx_ll_yes
	from carlx
	where (borrowertypecode between 21 and 34 or borrowertypecode = 46)
	group by borrowertypecode 
	order by borrowertypecode
) x1 on x.borrowertypecode = x1.borrowertypecode
left outer join (
	select borrowertypecode, 
	count(patronid) as ic_ll_yes
	from infinitecampus
	where limitlesslibraryuse = 'Yes' 
	group by borrowertypecode 
	order by borrowertypecode
) i1 on x.borrowertypecode = i1.borrowertypecode
left outer join (
	select borrowertypecode, 
	count(patronid) as cx_ll_no
	from carlx
	where (borrowertypecode between 35 and 37 or borrowertypecode = 47)
	group by borrowertypecode 
	order by borrowertypecode
) x0 on x.borrowertypecode = x0.borrowertypecode
left outer join (
	select borrowertypecode, 
	count(patronid) as ic_ll_no
	from infinitecampus
	where limitlesslibraryuse = 'No' 
	group by borrowertypecode 
	order by borrowertypecode
) i0 on x.borrowertypecode = i0.borrowertypecode

;
.headers on
.output ../data/ic2carlx_mnps_students_report_borrowertypecode.csv
select borrowertypecode,
	carlx,
	infinitecampus,
	created,
	updated,
	removed
	cx_ll_yes,
	ic_ll_yes,
	cx_ll_no,
	ic_ll_no
from report_borrowertypecode
where report_borrowertypecode.date = CURRENT_DATE
;
.output stdout

-- REPORT x BORROWER TYPE : ABORT PATRON LOAD
.headers on
.output ../data/ic2carlx_mnps_students_report_borrowertypecode_ABORT.csv
select *
from report_borrowertypecode
where date = CURRENT_DATE
and borrowertypecode != '38'
and (infinitecampus <= carlx*.9
	or created >= carlx*.1
	or updated >= carlx*.1
	or removed >= carlx*.1
	or abs(cx_ll_yes - ic_ll_yes)/cx_ll_yes >= .1
)
;
.output stdout
