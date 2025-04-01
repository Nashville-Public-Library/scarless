drop table if exists mackin_data;
create table mackin_data (
    mackinvia_account_id integer
    , yearmonthday integer
    , user_id text
    , count_of_checkouts integer
);
.mode csv
.import "../data/mackin/Nashville daily VIA report_DATEPLACEHOLDERMMDDYYYY.csv" mackin_data
.headers on
.output "../data/LibraryServices-Checkouts-MackinVIA-student-DATEPLACEHOLDERYYYYMMDD.csv"
select
    ms.tn_school_code
     , md.yearmonthday
     , i.patronid
     , md.count_of_checkouts
from mackin_data md
    left join mackinvia_school_lookup ms on md.mackinvia_account_id = ms.mackin_school_id
    left join infinitecampus i on lower(md.user_id) = lower(i.emailaddress);
.output stdout