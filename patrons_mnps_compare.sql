-- patrons_mnps_compare.sql
-- James Staub
-- Nashville Public Library
-- Using shell instead of php as per https://stackoverflow.com/questions/35999597/importing-csv-file-into-sqlite3-from-php#36001304
-- TO DO: bug in SQLite 3.7.17 forces us to create the table before importing data into it. See https://stackoverflow.com/questions/38035543/sqlite3-import-csv-not-working
-- TO DO: determine the benefit and method of useing prepare()

DROP TABLE IF EXISTS carlx;
-- CREATE TABLE carlx (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryStreetAddress,SecondaryCity,SecondaryState,SecondaryZipCode,PrimaryPhoneNumber,SecondaryPhoneNumber,AlternateID,NonvalidatedStats,DefaultBranch,ValidatedStatCodes,StatusCode,RegistrationDate,LastActionDate,ExpirationDate,EmailAddress,Notes,BirthDate,Guardian,RacialorEthnicCategory,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName);
CREATE TABLE carlx (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryPhoneNumber,DefaultBranch,ExpirationDate,EmailAddress,BirthDate,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName);

DROP TABLE IF EXISTS infinitecampus;
-- CREATE TABLE infinitecampus (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryStreetAddress,SecondaryCity,SecondaryState,SecondaryZipCode,PrimaryPhoneNumber,SecondaryPhoneNumber,AlternateID,NonvalidatedStats,DefaultBranch,ValidatedStatCodes,StatusCode,RegistrationDate,LastActionDate,ExpirationDate,EmailAddress,Notes,BirthDate,Guardian,RacialorEthnicCategory,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName);
CREATE TABLE infinitecampus (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryPhoneNumber,DefaultBranch,ExpirationDate,EmailAddress,BirthDate,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName);

.mode csv
.import ../data/patrons_mnps_carlx.csv carlx
.import ../data/patrons_mnps_infinitecampus.csv infinitecampus

-- ADD TO CARLX
.output ../data/patrons_mnps_carlx_add.csv
select infinitecampus.*
from infinitecampus
left join carlx on infinitecampus.PatronID = carlx.PatronID
where carlx.PatronID IS NULL
order by infinitecampus.PatronID
;

-- UPDATE CARLX
.output ../data/patrons_mnps_carlx_update.csv
select infinitecampus.* from infinitecampus
except
select * from carlx
except
	select infinitecampus.*
	from infinitecampus
	left join carlx on infinitecampus.PatronID = carlx.PatronID
	where carlx.PatronID IS NULL
	order by infinitecampus.PatronID
;

-- CreatePatronUserDefinedFields UDF1 TechOptOut 
.output ../data/patrons_mnps_carlx_createUdf1.csv
select i.* 
from (
        select infinitecampus.PatronID as patronid, 
		'0' as occur, 
		'1' as fieldid, 
		case when infinitecampus.TechOptOut = 'Yes' then '1' else '2' end as numcode, 
		'0' as type, 
		infinitecampus.TechOptOut as valuename
        from infinitecampus
) i left join (
        select carlx.PatronID, carlx.TechOptOut
        from carlx
) c on i.PatronID = c.PatronID
where c.PatronID IS NULL
order by i.PatronID
;

-- CreatePatronUserDefinedFields UDF3 LapTopCheckOut 
.output ../data/patrons_mnps_carlx_createUdf3.csv
select i.* 
from (
        select infinitecampus.PatronID as patronid, 
		'0' as occur, 
		'1' as fieldid, 
		case when infinitecampus.LapTopCheckOut = 'Yes' then '1' else '2' end as numcode, 
		'0' as type, 
		infinitecampus.LapTopCheckOut as valuename
        from infinitecampus
) i left join (
        select carlx.PatronID, carlx.LapTopCheckOut
        from carlx
) c on i.PatronID = c.PatronID
where c.PatronID IS NULL
order by i.PatronID
;

-- CreatePatronUserDefinedFields UDF4 LimitlessLibraryUse
.output ../data/patrons_mnps_carlx_createUdf4.csv
select i.* 
from (
        select infinitecampus.PatronID as patronid, 
		'0' as occur, 
		'1' as fieldid, 
		case when infinitecampus.LimitlessLibraryUse = 'Yes' then '1' else '2' end as numcode, 
		'0' as type, 
		infinitecampus.LimitlessLibraryUse as valuename
        from infinitecampus
) i left join (
        select carlx.PatronID, carlx.LimitlessLibraryUse
        from carlx
) c on i.PatronID = c.PatronID
where c.PatronID IS NULL
order by i.PatronID
;

-- UpdatePatronUserDefinedFields
