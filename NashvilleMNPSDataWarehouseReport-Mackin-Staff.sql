drop table if exists mackin_data;
create table mackin_data (
    mackinvia_account_id integer
    , yearmonthday integer
    , user_id text
    , count_of_checkouts integer
);
.mode csv
.import "../data/mackin/Nashville daily VIA report_staff_DATEPLACEHOLDERMMDDYYYY.csv" mackin_data
-- Ensure the first row (headers) is not included in the data
delete from mackin_data where rowid = 1;
.headers on
.output "../data/LibraryServices-Checkouts-MackinVIA-staff-DATEPLACEHOLDERYYYYMMDD.csv"
select
    ms.tn_school_code
     , md.yearmonthday
     , i.patronid
     , md.count_of_checkouts
from mackin_data md
    left join mackinvia_school_lookup ms on md.mackinvia_account_id = ms.mackin_school_id
    -- as of 2025 05 10, we are getting email addresses from Mackin that look like jqdoe@mnps.org instead of John.Doe@mnps.org
    -- the join below attempts to use the data we have on hand for a match
    left join infinitecampus i on (
      (
          substr(upper(md.user_id), 1, 1) = substr(upper(i.patronfirstname), 1, 1)
              and substr(upper(md.user_id), 2, instr(upper(md.user_id), '@') - 2) = upper(i.patronlastname)
          )
          or
      (
          substr(upper(md.user_id), 1, 1) = substr(upper(i.patronfirstname), 1, 1)
              and substr(upper(md.user_id), 2, 1) = substr(upper(i.patronmiddlename), 1, 1)
              and substr(upper(md.user_id), 3, instr(upper(md.user_id), '@') - 3) = upper(i.patronlastname)
          )
    )
-- where i.borrowertypecode in (13, 40) -- unnecessary: we are looking at the staff (-only) database
    where ms.carlx_branchcode = i.defaultbranch
;
.output stdout