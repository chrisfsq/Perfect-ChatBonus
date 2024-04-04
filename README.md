Import table in pw database: 

<code>USE pw;

CREATE TABLE IF NOT EXISTS `usebonuslog` (
  `userid` int(11) NOT NULL,
  `bonuslog` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8; </code>
