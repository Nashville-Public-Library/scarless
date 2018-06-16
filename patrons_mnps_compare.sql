-- patrons_mnps_compare.sql
-- James Staub
-- Nashville Public Library
-- Using shell instead of php as per https://stackoverflow.com/questions/35999597/importing-csv-file-into-sqlite3-from-php#36001304
-- TO DO: bug in SQLite 3.7.17 forces us to create the table before importing data into it. See https://stackoverflow.com/questions/38035543/sqlite3-import-csv-not-working

DROP TABLE IF EXISTS carlx;
-- CREATE TABLE carlx (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryStreetAddress,SecondaryCity,SecondaryState,SecondaryZipCode,PrimaryPhoneNumber,SecondaryPhoneNumber,AlternateID,NonvalidatedStats,DefaultBranch,ValidatedStatCodes,StatusCode,RegistrationDate,LastActionDate,ExpirationDate,EmailAddress,Notes,BirthDate,Guardian,RacialorEthnicCategory,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName);
CREATE TABLE carlx (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryPhoneNumber,DefaultBranch,ExpirationDate,EmailAddress,BirthDate,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName);

DROP TABLE IF EXISTS infinitecampus;
-- CREATE TABLE infinitecampus (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryStreetAddress,SecondaryCity,SecondaryState,SecondaryZipCode,PrimaryPhoneNumber,SecondaryPhoneNumber,AlternateID,NonvalidatedStats,DefaultBranch,ValidatedStatCodes,StatusCode,RegistrationDate,LastActionDate,ExpirationDate,EmailAddress,Notes,BirthDate,Guardian,RacialorEthnicCategory,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName);
CREATE TABLE infinitecampus (PatronID,Borrowertypecode,Patronlastname,Patronfirstname,Patronmiddlename,Patronsuffix,PrimaryStreetAddress,PrimaryCity,PrimaryState,PrimaryZipCode,SecondaryPhoneNumber,DefaultBranch,ExpirationDate,EmailAddress,BirthDate,LapTopCheckOut,LimitlessLibraryUse,TechOptOut,TeacherID,TeacherName);

.mode csv
.import ../data/patrons_mnps_carlx.csv carlx
.import ../data/patrons_mnps_infinitecampus.csv infinitecampus

