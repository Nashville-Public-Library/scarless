drop table if exists comicsplus_data;
create table comicsplus_data (
    library_id integer
    , activity_date text
    , patron_full text
    , checkouts integer
);

drop table if exists carlx_school_lookup;
create table carlx_school_lookup (
    patronid text
    , tn_school_code text
);

-- Ensure the comicsplus_school_lookup table exists to avoid errors
create table if not exists comicsplus_school_lookup (
    comicsplus_library_id integer
    , tn_school_code text
);

.mode csv
.import "../data/comicsplus/ComicsPlus_Report_DATEPLACEHOLDER.csv" comicsplus_data
-- Handle potential empty lookup file
.import "../data/comicsplus/ComicsPlus_School_Lookup_DATEPLACEHOLDER.csv" carlx_school_lookup

delete from comicsplus_data where typeof(library_id) = 'text' and library_id = 'library_id';
delete from carlx_school_lookup where patronid = 'patronid';

.headers on
.output "../data/LibraryServices-Checkouts-ComicsPlus-student-DATEPLACEHOLDERYYYYMMDD.csv"
select
    coalesce(csl.tn_school_code, ms.tn_school_code) as tn_school_code
     , substr(cd.activity_date, 1, 10) as yearmonthday
     , case 
         when instr(cd.patron_full, '@') > 0 then substr(cd.patron_full, 1, instr(cd.patron_full, '@') - 1)
         else cd.patron_full
       end as patronid
     , sum(cd.checkouts) as count_of_checkouts
from comicsplus_data cd
    left join comicsplus_school_lookup ms on cd.library_id = ms.comicsplus_library_id
    left join carlx_school_lookup csl on patronid = csl.patronid
group by 1, 2, 3;
.output stdout
