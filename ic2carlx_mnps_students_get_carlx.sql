select patron_v2.patronid as "Patron ID"						-- 00
  , patron_v2.bty as "Borrower type code"					-- 01
  , patron_v2.lastname as "Patron last name"					-- 02
  , patron_v2.firstname as "Patron first name"					-- 03
  , patron_v2.middlename as "Patron middle name"					-- 04
  , patron_v2.suffixname as "Patron suffix"					-- 05
  , patron_v2.street1 as "Primary Street Address"				-- 06
  , patron_v2.city1 as "Primary City"						-- 07
  , patron_v2.state1 as "Primary State"						-- 08
  , patron_v2.zip1 as "Primary Zip Code"						-- 09
--  , '' as "Secondary Street Address"						-- 10
--  , '' as "Secondary City"							-- 11
--  , '' as "Secondary State"							-- 12
--  , '' as "Secondary Zip Code"						-- 13
  , patron_v2.ph2 as "Secondary Phone Number"					-- 14 -- CONFUSING. MNPS SUPPLIES PRIMARY HOME PHONE. NPL LOADS INTO SECONDARY PHONE BECAUSE STUDENTS SHOULD NOT RECEIVE ITIVA AUTOMATED CALLS
--  , '' as "Secondary Phone Number"						-- 15
--  , '' as "Alternate ID"							-- 16
--  , '' as "Non-validated Stats"						-- 17
  , patronbranch.branchcode as "Default Branch"					-- 18
--  , '' as "Validated Stat Codes"						-- 19
-- TO DO: establish logic for patron status
-- , patron_v2.status as "Status Code"
--  , '' as "Status Code"							-- 20
--  , '' as "Registration Date"							-- 21
--  , '' as "Last Action Date"							-- 22
  , to_char(jts.todate(patron_v2.expdate),'YYYY-MM-DD') as "Expiration Date"	-- 23
  , patron_v2.email as "Email Address"						-- 24
--  , '' as "Notes"								-- 25
  , to_char(jts.todate(patron_v2.birthdate),'YYYY-MM-DD') as "Birth Date"	-- 26
  , guarantor.guarantor as "Guarantor"						-- 27
--  , udf2.valuename as "Racial or Ethnic Category"				-- 28
--  , udf3.valuename as "Lap Top Check Out"					-- 29*
--  , udf4.valuename as "Limitless Library Use"					-- 30*
--  , udf1.valuename as "Tech Opt Out"						-- 31*
  , '' as "Lap Top Check Out"							-- 29
  , '' as "Limitless Library Use"						-- 30
  , '' as "Tech Opt Out"							-- 31
  , patron_v2.street2 as "Teacher ID"						-- 32
  , patron_v2.sponsor as "Teacher Name"						-- 33
  , patron_v2.emailnotices as "Email Notices"					-- 34
  , expired.noteids as "Expired MNPS Note IDs"					-- 35
  , gDeleteNotes.deleteGuarantorNotes as "Delete Guarantor Note IDs"		-- 36
  , patron_v2.collectionstatus as "Collection Status"				-- 37
  , editbranch.branchcode as "Edit Branch"					-- 38
  , patron_v2.ph1 as "Primary Phone Number"					-- 39

from patron_v2
left outer join branch_v2 patronbranch on patron_v2.defaultbranch = patronbranch.branchnumber
left outer join (
  select distinct
    refid
    , upper(trim(regexp_substr(first_value(text) over (partition by refid order by timestamp desc),'[-/0-9]+:.+$'))) as guarantor
  from patronnotetext_v2
  where regexp_like(patronnotetext_v2.text, 'NPL: MNPS Guarantor effective')
) guarantor on patron_v2.patronid = guarantor.refid
/* DISABLED 2019 05 17
left outer join (
  select distinct
    patronid
    , fieldid
    , valuename
  from udfpatron_v2
  where udfpatron_v2.fieldid = 1
) udf1 on patron_v2.patronid = udf1.patronid
left outer join (
  select distinct
    patronid
    , fieldid
    , valuename
  from udfpatron_v2
  where udfpatron_v2.fieldid = 2
) udf2 on patron_v2.patronid = udf2.patronid
left outer join (
  select distinct
    patronid
    , fieldid
    , valuename
  from udfpatron_v2
  where udfpatron_v2.fieldid = 3
) udf3 on patron_v2.patronid = udf3.patronid
left outer join (
  select distinct
    patronid
    , fieldid
    , valuename
  from udfpatron_v2
  where udfpatron_v2.fieldid = 4
) udf4 on patron_v2.patronid = udf4.patronid
*/
left outer join (
  select refid
    , listagg(noteid,',') within group (order by timestamp desc) as noteids
  from patronnotetext_v2
  where regexp_like(patronnotetext_v2.text, 'MNPS patron expired')
  group by refid
) expired on patron_v2.patronid = expired.refid
left outer join (
  select gDelete.refid
      , listagg(gDelete.noteid,',') within group(order by gDelete.noteid) as deleteGuarantorNotes
  from patronnotetext_v2 gDelete
  left join
    (
      select * from
      (
        select 
          n.refid
          , n.noteid
          , to_date(trim(regexp_substr(n.text, '^NPL: MNPS Guarantor effective (\d+?/\d+?/\d+?) - (\d+?/\d+?/\d+?):',1,1,'i',1)),'DS') as gStart
          , to_date(trim(regexp_substr(n.text, '^NPL: MNPS Guarantor effective (\d+?/\d+?/\d+?) - (\d+?/\d+?/\d+?):',1,1,'i',2)),'DS') as gStop
        from patronnotetext_v2 n
        where regexp_like(n.text, 'NPL: MNPS Guarantor effective (\d+?/\d+?/\d+?) - (\d+?/\d+?/\d+?):')
        order by n.refid, n.noteid
      ) n
      inner join (
        select distinct t.patronid
          , trunc(jts.todate(t.transdate)) as tdate
        from transitem_v2 t
        where  t.transcode in ('C', 'O', 'L','F1','F2','FS')
        order by t.patronid
      ) o on n.refid = o.patronid 
          and n.gStart <= o.tdate 
          and n.gStop >= o.tdate
    ) gKeep on gDelete.noteid = gKeep.noteid
  where regexp_like(gDelete.text, 'NPL: MNPS Guarantor effective (\d+?/\d+?/\d+?) - (\d+?/\d+?/\d+?):')
  and to_date(trim(regexp_substr(gDelete.text, 'NPL: MNPS Guarantor effective (\d+?/\d+?/\d+?) - (\d+?/\d+?/\d+?):',1,1,'i',2)),'DS') < trunc(sysdate)
  and gKeep.noteid is null
  group by gDelete.refid
) gDeleteNotes on patron_v2.patronid = gDeleteNotes.refid
left outer join	branch_v2 editbranch on patron_v2.editbranch = editbranch.branchnumber
where
--  patronbranch.branchgroup = '2'
--  or 
  ((patron_v2.bty >= 21 and patron_v2.bty <= 38) or (patron_v2.bty >= 46 and patron_v2.bty <= 47))
  or regexp_like(patron_v2.patronid,'^190[0-9]{6}$')
  or regexp_like(patron_v2.patronid,'^190999[0-9]{3}$') -- TEST MNPS STUDENT PATRONS
order by patron_v2.patronid
